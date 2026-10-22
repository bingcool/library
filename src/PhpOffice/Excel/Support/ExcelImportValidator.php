<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\Support;

use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelImportException;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelImportRowException;
use Vtiful\Kernel\Excel;

/**
 * Excel 导入前置校验：文件、sheet、表头、行宽、列类型。
 */
final class ExcelImportValidator
{
    public const MAX_ROWS = 1048576;
    public const MAX_COLUMNS = 16384;
    public const MAX_CELL_LENGTH = 32767;

    /**
     * @return string realpath 规范化后的文件路径
     */
    public static function normalizeFile(string $file): string
    {
        $file = trim($file);
        if ($file === '') {
            throw new InvalidExcelImportException('Excel import file path must not be empty');
        }

        $realPath = realpath($file);
        if ($realPath === false || !is_file($realPath)) {
            throw new InvalidExcelImportException(sprintf('Excel import file [%s] does not exist', $file));
        }

        if (!is_readable($realPath)) {
            throw new InvalidExcelImportException(sprintf('Excel import file [%s] is not readable', $realPath));
        }

        $size = filesize($realPath);
        if ($size === false || $size <= 0) {
            throw new InvalidExcelImportException(sprintf('Excel import file [%s] is empty', $realPath));
        }

        return $realPath;
    }

    /**
     * @param list<string> $sheetNames
     */
    public static function resolveSheetName(?string $requested, array $sheetNames): string
    {
        $names = [];
        foreach ($sheetNames as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            throw new InvalidExcelImportException('Excel workbook has no readable sheets');
        }

        if ($requested === null) {
            return $names[0];
        }

        $requested = trim($requested);
        if ($requested === '') {
            throw new InvalidExcelImportException('sheetName() must return null or a non-empty sheet name');
        }

        if (!in_array($requested, $names, true)) {
            throw new InvalidExcelImportException(sprintf(
                'Sheet [%s] does not exist; available sheets: %s',
                $requested,
                implode(', ', $names)
            ));
        }

        return $requested;
    }

    public static function normalizeHeaderRow(int $headerRow): int
    {
        if ($headerRow < 0 || $headerRow > self::MAX_ROWS) {
            throw new InvalidExcelImportException(sprintf(
                'headerRow() must return 0 to %d (%d given)',
                self::MAX_ROWS,
                $headerRow
            ));
        }

        return $headerRow;
    }

    public static function resolveDataStartRow(int $dataStartRow, int $headerRow): int
    {
        if ($dataStartRow < 0 || $dataStartRow > self::MAX_ROWS) {
            throw new InvalidExcelImportException(sprintf(
                'dataStartRow() must return 0 to %d (%d given)',
                self::MAX_ROWS,
                $dataStartRow
            ));
        }

        if ($headerRow === 0) {
            return $dataStartRow === 0 ? 1 : $dataStartRow;
        }

        $start = $dataStartRow === 0 ? $headerRow + 1 : $dataStartRow;
        if ($start <= $headerRow) {
            throw new InvalidExcelImportException(sprintf(
                'dataStartRow() must be greater than headerRow() (%d given)',
                $start
            ));
        }

        if ($start > self::MAX_ROWS) {
            throw new InvalidExcelImportException(sprintf(
                'dataStartRow() must not exceed %d (%d given)',
                self::MAX_ROWS,
                $start
            ));
        }

        return $start;
    }

    /**
     * @return list<string>|null
     */
    public static function normalizeExpectedHeaders(?array $headers): ?array
    {
        if ($headers === null) {
            return null;
        }

        if (!array_is_list($headers) || $headers === []) {
            throw new InvalidExcelImportException('expectedHeaders() must return null or a non-empty list');
        }

        return self::normalizeHeaderValues($headers, 'expectedHeaders()');
    }

    /**
     * @param array<int|string, mixed> $cells
     * @return list<string>
     */
    public static function normalizeHeaderRowCells(array $cells, int $rowNumber): array
    {
        if ($cells === []) {
            throw new InvalidExcelImportException(sprintf('Excel header row %d is empty', $rowNumber));
        }

        return self::normalizeHeaderValues(
            array_values($cells),
            sprintf('header row %d', $rowNumber)
        );
    }

    /**
     * @param list<string> $actual
     * @param list<string> $expected
     */
    public static function assertHeaderMatches(
        array $actual,
        array $expected,
        int $rowNumber,
        string $sheetName
    ): void {
        if ($actual === $expected) {
            return;
        }

        throw new InvalidExcelImportException(sprintf(
            'Excel header mismatch in sheet [%s] row %d; expected [%s], actual [%s]',
            $sheetName,
            $rowNumber,
            implode(' | ', $expected),
            implode(' | ', $actual)
        ));
    }

    /**
     * 按表头宽度补齐/截断空尾列；非空的多余列视为模板错误。
     *
     * @param array<int|string, mixed> $cells
     * @param list<string> $headers
     * @return list<mixed>
     */
    public static function normalizeDataCells(array $cells, array $headers, int $rowNumber): array
    {
        $cells = array_values($cells);
        $width = count($headers);

        if ($width === 0) {
            return $cells;
        }

        if (count($cells) > $width) {
            $extras = array_slice($cells, $width);
            foreach ($extras as $offset => $value) {
                if (self::isEmptyCell($value)) {
                    continue;
                }

                $columnIndex = $width + $offset + 1;
                throw new InvalidExcelImportRowException(sprintf(
                    'Excel row %d column %d (%s) has a value but no matching header',
                    $rowNumber,
                    $columnIndex,
                    ColumnLetter::fromIndex($columnIndex)
                ), $rowNumber, $columnIndex, ColumnLetter::fromIndex($columnIndex));
            }

            $cells = array_slice($cells, 0, $width);
        }

        return array_pad($cells, $width, '');
    }

    /**
     * @param mixed $cells normalizeCells() 的返回值
     * @return list<mixed>
     */
    public static function normalizeNormalizedCells(mixed $cells, int $rowNumber, int $expectedCount): array
    {
        if (!is_array($cells) || !array_is_list($cells)) {
            throw new InvalidExcelImportRowException(sprintf(
                'normalizeCells() must return a list for row %d, %s given',
                $rowNumber,
                get_debug_type($cells)
            ), $rowNumber);
        }

        if ($expectedCount > 0 && count($cells) !== $expectedCount) {
            throw new InvalidExcelImportRowException(sprintf(
                'normalizeCells() returned %d columns for row %d, expected %d',
                count($cells),
                $rowNumber,
                $expectedCount
            ), $rowNumber);
        }

        return $cells;
    }

    /**
     * @param array<int|string, mixed> $cells
     */
    public static function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $value) {
            if (!self::isEmptyCell($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, int> 0 基列序号 => Excel::TYPE_*
     */
    public static function normalizeCellTypes(array $types): array
    {
        $allowed = [
            Excel::TYPE_STRING,
            Excel::TYPE_INT,
            Excel::TYPE_DOUBLE,
            Excel::TYPE_TIMESTAMP,
        ];

        $normalized = [];
        foreach ($types as $columnIndex => $type) {
            if (!is_int($columnIndex) || $columnIndex < 0 || $columnIndex >= self::MAX_COLUMNS) {
                throw new InvalidExcelImportException(sprintf(
                    'cellTypes() column index must be 0 to %d (%s given)',
                    self::MAX_COLUMNS - 1,
                    (string) $columnIndex
                ));
            }

            if (!is_int($type) || !in_array($type, $allowed, true)) {
                throw new InvalidExcelImportException(sprintf(
                    'cellTypes() for column %d must be one of Excel::TYPE_STRING|INT|DOUBLE|TIMESTAMP (%s given)',
                    $columnIndex,
                    get_debug_type($type)
                ));
            }

            $normalized[$columnIndex] = $type;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function normalizeHeaderValues(array $values, string $source): array
    {
        $normalized = [];
        $seen = [];

        foreach ($values as $index => $value) {
            $columnIndex = $index + 1;
            $columnLetter = ColumnLetter::fromIndex($columnIndex);

            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new InvalidExcelImportException(sprintf(
                    'Excel %s column %d (%s) must be string|int|float, %s given',
                    $source,
                    $columnIndex,
                    $columnLetter,
                    get_debug_type($value)
                ));
            }

            $header = trim((string) $value);
            if ($header === '') {
                throw new InvalidExcelImportException(sprintf(
                    'Excel %s column %d (%s) must not be empty',
                    $source,
                    $columnIndex,
                    $columnLetter
                ));
            }

            if (mb_strlen($header) > self::MAX_CELL_LENGTH) {
                throw new InvalidExcelImportException(sprintf(
                    'Excel %s column %d (%s) exceeds %d characters',
                    $source,
                    $columnIndex,
                    $columnLetter,
                    self::MAX_CELL_LENGTH
                ));
            }

            if (isset($seen[$header])) {
                throw new InvalidExcelImportException(sprintf(
                    'Excel %s column %d (%s) duplicates header [%s]',
                    $source,
                    $columnIndex,
                    $columnLetter,
                    $header
                ));
            }

            $seen[$header] = true;
            $normalized[] = $header;
        }

        if ($normalized === []) {
            throw new InvalidExcelImportException(sprintf('Excel %s must not be empty', $source));
        }

        return $normalized;
    }

    private static function isEmptyCell(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
