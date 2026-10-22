<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel;

use Swoolefy\Library\PhpOffice\Excel\Exception\ExcelExportException;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelExportException;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelRowException;
use Swoolefy\Library\PhpOffice\Excel\Support\ExcelExportValidator;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelExportContext;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelExportResult;
use Vtiful\Kernel\Excel;
use Vtiful\Kernel\Format;

/**
 * 基于 ext-xlswriter 的同步 Excel 导出模板。
 *
 * 子类只需实现 headers() 与 rows()；简单需求覆写声明式钩子（文件名、sheet 名、列宽、
 * 冻结、筛选、日期格式、表头样式），复杂布局覆写 writeHeader() / writeRows() /
 * afterRows() 等扩展点。export() 固定文件生命周期：校验 → 排他预留 → 写入 → 收尾 → 结果。
 *
 * 单实例只能导出一次；rows() 常为 Generator，重复迭代会得到空数据。
 */
abstract class AbstractExcelExport
{
    private const DEFAULT_BATCH_SIZE = 1000;

    private bool $exported = false;

    // ---- 必须实现：表结构 ----

    /** 表头，非空列表；决定每行列数 */
    abstract protected function headers(): array;

    /** 数据行，允许 Generator；默认写入流程要求每行与表头等宽 */
    abstract protected function rows(): iterable;

    // ---- 声明式配置（简单导出） ----

    /** 不含目录的文件名；基类负责补 .xlsx、校验与防覆盖 */
    protected function fileName(): string
    {
        return (new \ReflectionClass($this))->getShortName()
            . '_' . date('YmdHis')
            . '_' . bin2hex(random_bytes(3));
    }

    protected function sheetName(): string
    {
        return 'Sheet1';
    }

    /**
     * Excel 列范围 => 宽度，例如 ['A' => 24, 'B:C' => 18]
     *
     * @return array<string, int|float>
     */
    protected function columnWidths(): array
    {
        return [];
    }

    /** 冻结行数；0=不冻结，默认冻结首行 */
    protected function freezeRowCount(): int
    {
        return 1;
    }

    protected function autoFilter(): bool
    {
        return true;
    }

    /** true=constant memory 模式（默认），false=普通模式 */
    protected function useConstMemory(): bool
    {
        return true;
    }

    protected function batchSize(): int
    {
        return self::DEFAULT_BATCH_SIZE;
    }

    /** DateTimeInterface 的字符串格式；使用对象自身时区 */
    protected function dateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    // ---- 表头 / 样式扩展 ----

    /**
     * 建表后、写任何单元格之前调用。
     * 只能做非单元格配置；不要在此写单元格，否则默认 writeHeader() 的 header()
     * 会因「必须先于任何写入」而抛异常。
     */
    protected function configureSheet(Excel $excel): void
    {
    }

    /** 覆写可完全替换默认表头样式；需要保留默认样式时先 parent::configureHeaderFormat($format) */
    protected function configureHeaderFormat(Format $format): void
    {
        $format->bold()
            ->background(0x4F81BD)
            ->fontColor(0xFFFFFF)
            ->fontSize(11)
            ->align(Format::FORMAT_ALIGN_CENTER, Format::FORMAT_ALIGN_VERTICAL_CENTER)
            ->border(Format::BORDER_THIN);
    }

    /**
     * 写表头，返回表头占用行数（0=无表头）。
     * 默认写一行；多行表头/合并标题/完全自定义表头在此覆写。
     *
     * @param resource $headerFormat configureHeaderFormat() 生成的格式资源
     */
    protected function writeHeader(Excel $excel, array $headers, $headerFormat): int
    {
        $excel->header($headers, $headerFormat);

        return 1;
    }

    // ---- 数据写入扩展 ----

    /**
     * 写数据行，返回数据行数。
     * 默认实现：逐行 normalizeRow() 校验 + 按 batchSize() 调用 data()。
     * 完全自定义布局时覆写；可调用 parent::writeRows() 复用校验/分批。
     */
    protected function writeRows(Excel $excel, iterable $rows, array $headers): int
    {
        $rowCount = 0;
        $batch = [];

        foreach ($rows as $row) {
            $rowCount++;
            if ($rowCount > ExcelExportValidator::MAX_DATA_ROWS) {
                throw new InvalidExcelExportException(sprintf(
                    'Excel data rows exceed the limit of %d',
                    ExcelExportValidator::MAX_DATA_ROWS
                ));
            }

            if (!is_array($row)) {
                throw new InvalidExcelRowException(sprintf(
                    'Excel row %d must be an array, %s given',
                    $rowCount,
                    get_debug_type($row)
                ), $rowCount);
            }

            $batch[] = $this->normalizeRow($row, $rowCount, $headers);
            if (count($batch) >= $this->batchSize()) {
                $excel->data($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $excel->data($batch);
        }

        return $rowCount;
    }

    /**
     * 单元格归一化；自定义 writeRows 可复用。
     *
     * @param list<string> $headers
     * @return list<bool|float|int|string>
     */
    protected function normalizeRow(array $row, int $rowNumber, array $headers): array
    {
        return ExcelExportValidator::normalizeRow($row, $rowNumber, $headers, $this->dateFormat());
    }

    // ---- 收尾扩展 ----

    /** 数据写完后、自动筛选前；适合 mergeCells / 合计行 / 公式 / 插图 */
    protected function afterRows(Excel $excel, ExcelExportContext $context): void
    {
    }

    /**
     * 返回 null 表示不添加筛选；多行表头/含合计行时覆写。
     * constMemory 模式要求范围结束行等于最后写入行，需排除 afterRows() 追加行时关闭 constMemory。
     */
    protected function autoFilterRange(ExcelExportContext $context): ?string
    {
        return $context->defaultAutoFilterRange();
    }

    /** 自动筛选后、output 前；最后的单元格级调整机会 */
    protected function beforeOutput(Excel $excel, ExcelExportContext $context): void
    {
    }

    /**
     * @throws ExcelExportException 实例复用、xlswriter 写入失败或 output 未产出有效文件
     * @throws InvalidExcelExportException 目录、表头、sheet 名、文件名、列宽、钩子返回值非法
     * @throws InvalidExcelRowException 行数据不合法
     */
    public function export(string $directory): ExcelExportResult
    {
        if ($this->exported) {
            throw new ExcelExportException('This Excel export instance has already been exported');
        }
        $this->exported = true;

        ExcelExportValidator::assertExtensionLoaded();

        $directory = ExcelExportValidator::normalizeDirectory($directory);
        $headers = ExcelExportValidator::normalizeHeaders($this->headers());
        $sheetName = ExcelExportValidator::normalizeSheetName($this->sheetName());
        $fileName = ExcelExportValidator::normalizeFileName($this->fileName());
        $columnWidths = ExcelExportValidator::normalizeColumnWidths($this->columnWidths());
        $batchSize = ExcelExportValidator::normalizeBatchSize($this->batchSize());

        $path = $directory . DIRECTORY_SEPARATOR . $fileName;
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new InvalidExcelExportException(sprintf(
                'Excel file [%s] already exists or is not writable',
                $path
            ));
        }
        fclose($handle);

        $excel = null;
        $outputPath = null;

        try {
            $excel = new Excel(['path' => $directory]);
            if ($this->useConstMemory()) {
                $excel->constMemory($fileName, $sheetName);
            } else {
                $excel->fileName($fileName, $sheetName);
            }

            $this->configureSheet($excel);

            $format = new Format($excel->getHandle());
            $this->configureHeaderFormat($format);
            $headerRowCount = ExcelExportValidator::normalizeHeaderRowCount(
                $this->writeHeader($excel, $headers, $format->toResource())
            );

            $excel->setCurrentLine($headerRowCount);

            foreach ($columnWidths as $range => $width) {
                $excel->setColumn($range, $width);
            }

            $freezeRowCount = ExcelExportValidator::normalizeFreezeRowCount(
                $this->freezeRowCount(),
                $headerRowCount
            );
            if ($freezeRowCount > 0) {
                $excel->freezePanes($freezeRowCount, 0);
            }

            $dataRowCount = ExcelExportValidator::normalizeDataRowCount(
                $this->writeRows($excel, $this->rows(), $headers),
                $headerRowCount
            );

            $context = new ExcelExportContext($headers, $headerRowCount, $dataRowCount, $batchSize);
            $this->afterRows($excel, $context);

            if ($this->autoFilter()) {
                $range = ExcelExportValidator::normalizeAutoFilterRange($this->autoFilterRange($context));
                if ($range !== null) {
                    $excel->autoFilter($range);
                }
            }

            $this->beforeOutput($excel, $context);

            $outputPath = $excel->output();
            if (!is_string($outputPath) || $outputPath === '') {
                throw new ExcelExportException('Excel output() did not return a file path');
            }

            clearstatcache(true, $outputPath);
            if (!is_file($outputPath)) {
                throw new ExcelExportException(sprintf('Excel output file [%s] was not created', $outputPath));
            }

            $size = filesize($outputPath);
            if ($size === false || $size <= 0) {
                throw new ExcelExportException(sprintf('Excel output file [%s] is empty', $outputPath));
            }

            $realPath = realpath($outputPath);

            return new ExcelExportResult(
                $realPath !== false ? $realPath : $outputPath,
                $fileName,
                $sheetName,
                $dataRowCount,
                $size
            );
        } catch (\Vtiful\Kernel\Exception $e) {
            $this->cleanup($excel, $path, $outputPath);
            throw new ExcelExportException('Excel export failed: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            $this->cleanup($excel, $path, $outputPath);
            throw $e;
        }
    }

    /**
     * 尽力关闭扩展句柄并删除预留/半成品文件；清理异常不覆盖原始异常。
     */
    private function cleanup(?Excel $excel, string $path, ?string $outputPath): void
    {
        if ($excel !== null) {
            try {
                $excel->close();
            } catch (\Throwable) {
            }
        }

        $files = [$path];
        if ($outputPath !== null) {
            $files[] = $outputPath;
        }

        foreach (array_unique($files) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
