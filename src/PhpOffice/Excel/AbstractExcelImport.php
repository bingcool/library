<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel;

use Swoolefy\Library\PhpOffice\Excel\Exception\ExcelImportException;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelImportException;
use Swoolefy\Library\PhpOffice\Excel\Support\ExcelExtension;
use Swoolefy\Library\PhpOffice\Excel\Support\ExcelImportValidator;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelImportResult;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelImportRow;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelReadResult;
use Vtiful\Kernel\Excel;

/**
 * 基于 ext-xlswriter 的流式 Excel 读取/导入模板。
 *
 * 读取整个文件用 read()；逐行落库用 import()，子类实现 handleRow() 即可。
 * 表头映射、空行处理、按列类型读取由基类完成，数据始终通过 nextRow() 流式读取。
 *
 * 单实例只能读取或导入一次；read() 会把全部数据行载入内存，import() 不会。
 */
abstract class AbstractExcelImport
{
    private bool $consumed = false;

    /**
     * 处理一行已归一化的数据；业务异常原样向上传播。
     * 仅 import() 调用；read() 不需要实现。
     */
    protected function handleRow(ExcelImportRow $row): void
    {
        throw new \LogicException(sprintf(
            '%s::handleRow() must be implemented to use import(); use read() to load rows without handling them',
            static::class
        ));
    }

    // ---- 声明式配置 ----

    /** 目标 sheet；null 表示第一个 sheet */
    protected function sheetName(): ?string
    {
        return null;
    }

    /** 表头行号（1 基）；0 表示无表头，数据从第一行开始 */
    protected function headerRow(): int
    {
        return 1;
    }

    /**
     * 数据起始行号（1 基）；0 表示自动：
     * headerRow() > 0 时为表头下一行，否则为第 1 行。
     */
    protected function dataStartRow(): int
    {
        return 0;
    }

    /** 期望的表头；非 null 时与文件表头严格比较，用于模板校验 */
    protected function expectedHeaders(): ?array
    {
        return null;
    }

    /**
     * 是否将所有单元格按字符串读取（默认 true）。
     * 保留前导零和长编号；需要数值/时间戳时通过 cellTypes() 按列覆盖。
     */
    protected function useStringType(): bool
    {
        return true;
    }

    /**
     * 按列指定读取类型，0 基列序号 => Excel::TYPE_STRING|INT|DOUBLE|TIMESTAMP。
     * 例：[3 => Excel::TYPE_TIMESTAMP] 把第 4 列日期转为 Unix 时间戳。
     *
     * @return array<int, int>
     */
    protected function cellTypes(): array
    {
        return [];
    }

    /** true 时空数据行不调用 handleRow()，计入 skippedRowCount */
    protected function skipEmptyRows(): bool
    {
        return true;
    }

    /**
     * 单元格归一化钩子，在补齐列宽后、构造 ExcelImportRow 前调用。
     * 返回的列表必须与表头列数一致（无表头时任意列数）。
     *
     * @param list<mixed> $cells
     * @return list<mixed>
     */
    protected function normalizeCells(array $cells, int $rowNumber): array
    {
        return $cells;
    }

    /** 所有数据行处理完后调用；适合落库前 flush 最后一批缓冲 */
    protected function afterImport(int $rowCount): void
    {
    }

    /**
     * 一次读取整个文件，返回全部数据行；不调用 handleRow() / afterImport()。
     * 全部行会驻留内存，大文件请改用 import() 流式落库。
     *
     * @throws ExcelImportException 实例复用、文件打开/读取失败
     * @throws InvalidExcelImportException 文件、sheet、表头、行列配置不合法
     * @throws \Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelImportRowException 行宽或单元格不合法
     */
    final public function read(string $file): ExcelReadResult
    {
        $this->markConsumed();

        $generator = $this->readRows($file, 'read');
        $rows = [];

        foreach ($generator as $row) {
            $rows[] = $row;
        }

        $meta = $generator->getReturn();

        return new ExcelReadResult(
            $meta['path'],
            $meta['sheetName'],
            $meta['headers'],
            $meta['headerRowNumber'],
            $rows,
            count($rows),
            $meta['skippedRowCount'],
            $meta['lastRowNumber']
        );
    }

    /**
     * 流式逐行读取并交给 handleRow()；适合大数据量落库。
     *
     * @throws ExcelImportException 实例复用、文件打开/读取失败
     * @throws InvalidExcelImportException 文件、sheet、表头、行列配置不合法
     * @throws \Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelImportRowException 行宽或单元格不合法
     * @throws \LogicException 子类未实现 handleRow()
     */
    public function import(string $file): ExcelImportResult
    {
        $this->markConsumed();

        $generator = $this->readRows($file, 'import');
        $rowCount = 0;

        foreach ($generator as $row) {
            $this->handleRow($row);
            $rowCount++;
        }

        $meta = $generator->getReturn();
        $this->afterImport($rowCount);

        return new ExcelImportResult(
            $meta['path'],
            $meta['sheetName'],
            $meta['headers'],
            $meta['headerRowNumber'],
            $rowCount,
            $meta['skippedRowCount'],
            $meta['lastRowNumber']
        );
    }

    private function markConsumed(): void
    {
        if ($this->consumed) {
            throw new ExcelImportException('This Excel import instance has already been imported or read');
        }

        $this->consumed = true;
    }

    /**
     * @return \Generator<int, ExcelImportRow, mixed, array{
     *     path: string,
     *     sheetName: string,
     *     headers: list<string>,
     *     headerRowNumber: int,
     *     skippedRowCount: int,
     *     lastRowNumber: int
     * }>
     */
    private function readRows(string $file, string $operation): \Generator
    {
        ExcelExtension::assertLoaded();

        $path = ExcelImportValidator::normalizeFile($file);
        $headerRow = ExcelImportValidator::normalizeHeaderRow($this->headerRow());
        $dataStartRow = ExcelImportValidator::resolveDataStartRow($this->dataStartRow(), $headerRow);
        $expectedHeaders = ExcelImportValidator::normalizeExpectedHeaders($this->expectedHeaders());

        if ($expectedHeaders !== null && $headerRow === 0) {
            throw new InvalidExcelImportException('expectedHeaders() requires headerRow() to be greater than 0');
        }

        $cellTypes = ExcelImportValidator::normalizeCellTypes($this->cellTypes());

        $excel = null;

        try {
            $excel = new Excel(['path' => dirname($path)]);
            $excel->openFile(basename($path));
            $sheetName = ExcelImportValidator::resolveSheetName($this->sheetName(), $excel->sheetList());
            $excel->openSheet($sheetName);

            if ($this->useStringType()) {
                $excel->setGlobalType(Excel::TYPE_STRING);
            }
            if ($cellTypes !== []) {
                $excel->setType($cellTypes);
            }

            $headers = [];
            $skippedRowCount = 0;
            $currentRow = 0;
            $headerSeen = $headerRow === 0;

            while (($cells = $excel->nextRow()) !== null) {
                $currentRow++;

                if (!is_array($cells)) {
                    throw new ExcelImportException(sprintf(
                        'Failed to read sheet [%s] at row %d',
                        $sheetName,
                        $currentRow
                    ));
                }

                if ($headerRow > 0 && $currentRow === $headerRow) {
                    $headers = ExcelImportValidator::normalizeHeaderRowCells($cells, $currentRow);
                    if ($expectedHeaders !== null) {
                        ExcelImportValidator::assertHeaderMatches(
                            $headers,
                            $expectedHeaders,
                            $currentRow,
                            $sheetName
                        );
                    }
                    $headerSeen = true;
                    continue;
                }

                if ($currentRow < $dataStartRow) {
                    continue;
                }

                $cells = ExcelImportValidator::normalizeDataCells($cells, $headers, $currentRow);
                if ($this->skipEmptyRows() && ExcelImportValidator::isEmptyRow($cells)) {
                    $skippedRowCount++;
                    continue;
                }

                $cells = ExcelImportValidator::normalizeNormalizedCells(
                    $this->normalizeCells($cells, $currentRow),
                    $currentRow,
                    count($headers)
                );

                $values = $headers === [] ? [] : array_combine($headers, $cells);

                yield new ExcelImportRow($currentRow, $cells, $values);
            }

            if (!$headerSeen) {
                throw new InvalidExcelImportException(sprintf(
                    'Header row %d does not exist in sheet [%s]',
                    $headerRow,
                    $sheetName
                ));
            }

            return [
                'path' => $path,
                'sheetName' => $sheetName,
                'headers' => $headers,
                'headerRowNumber' => $headerRow,
                'skippedRowCount' => $skippedRowCount,
                'lastRowNumber' => $currentRow,
            ];
        } catch (\Vtiful\Kernel\Exception $e) {
            throw new ExcelImportException(
                sprintf('Excel %s failed: %s', $operation, $e->getMessage()),
                0,
                $e
            );
        } finally {
            if ($excel !== null) {
                try {
                    $excel->close();
                } catch (\Throwable) {
                }
            }
        }
    }
}
