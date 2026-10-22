<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\Support;

use DateTimeInterface;
use OutOfRangeException;
use Stringable;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelExportException;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelRowException;

/**
 * Excel 导出前置校验与单元格归一化。
 */
final class ExcelExportValidator
{
    public const MAX_HEADER_COLUMNS = 16384;
    public const MAX_HEADER_ROWS = 100;
    public const MAX_DATA_ROWS = 1048575;
    public const EXCEL_MAX_ROWS = 1048576;
    public const MAX_CELL_LENGTH = 32767;
    public const MAX_BATCH_SIZE = 10000;
    public const MAX_FILE_NAME_LENGTH = 120;

    public static function assertExtensionLoaded(): void
    {
        ExcelExtension::assertLoaded();
    }

    /**
     * @return string realpath 规范化后的目录，无末尾分隔符（根目录除外）
     */
    public static function normalizeDirectory(string $directory): string
    {
        $directory = trim($directory);
        if ($directory === '') {
            throw new InvalidExcelExportException('Export directory must not be empty');
        }

        if (!is_dir($directory)) {
            throw new InvalidExcelExportException(sprintf('Export directory [%s] does not exist', $directory));
        }

        $realPath = realpath($directory);
        if ($realPath === false || !is_dir($realPath)) {
            throw new InvalidExcelExportException(sprintf('Export directory [%s] is invalid', $directory));
        }

        if (!is_writable($realPath)) {
            throw new InvalidExcelExportException(sprintf('Export directory [%s] is not writable', $realPath));
        }

        $normalized = rtrim($realPath, '/\\');

        return $normalized === '' ? DIRECTORY_SEPARATOR : $normalized;
    }

    /**
     * @return list<string>
     */
    public static function normalizeHeaders(array $headers): array
    {
        if (!array_is_list($headers) || $headers === []) {
            throw new InvalidExcelExportException('Excel headers must be a non-empty list');
        }

        if (count($headers) > self::MAX_HEADER_COLUMNS) {
            throw new InvalidExcelExportException(sprintf(
                'Excel headers exceed the limit of %d columns (%d given)',
                self::MAX_HEADER_COLUMNS,
                count($headers)
            ));
        }

        $normalized = [];
        foreach ($headers as $index => $header) {
            $columnIndex = $index + 1;
            $columnLetter = ColumnLetter::fromIndex($columnIndex);

            if (!is_string($header) && !is_int($header) && !is_float($header)) {
                throw new InvalidExcelExportException(sprintf(
                    'Excel header %d (%s) must be string|int|float, %s given',
                    $columnIndex,
                    $columnLetter,
                    get_debug_type($header)
                ));
            }

            $header = (string) $header;
            if (trim($header) === '') {
                throw new InvalidExcelExportException(sprintf(
                    'Excel header %d (%s) must not be empty',
                    $columnIndex,
                    $columnLetter
                ));
            }

            if (mb_strlen($header) > self::MAX_CELL_LENGTH) {
                throw new InvalidExcelExportException(sprintf(
                    'Excel header %d (%s) exceeds %d characters',
                    $columnIndex,
                    $columnLetter,
                    self::MAX_CELL_LENGTH
                ));
            }

            $normalized[] = $header;
        }

        return $normalized;
    }

    public static function normalizeSheetName(string $sheetName): string
    {
        $length = mb_strlen($sheetName);
        if ($length < 1 || $length > 31) {
            throw new InvalidExcelExportException(sprintf(
                'Excel sheet name must contain 1 to 31 characters (%d given)',
                $length
            ));
        }

        if (preg_match('/[\[\]:*?\/\\\\]/', $sheetName) === 1) {
            throw new InvalidExcelExportException(sprintf(
                'Excel sheet name [%s] contains forbidden characters [ ] : * ? / \\',
                $sheetName
            ));
        }

        if (str_starts_with($sheetName, "'") || str_ends_with($sheetName, "'")) {
            throw new InvalidExcelExportException(sprintf(
                'Excel sheet name [%s] must not start or end with an apostrophe',
                $sheetName
            ));
        }

        return $sheetName;
    }

    /**
     * 只接受 basename，自动补 .xlsx；目标文件是否存在由调用方以排他方式预留时判定。
     */
    public static function normalizeFileName(string $fileName): string
    {
        $fileName = trim($fileName);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            throw new InvalidExcelExportException('Excel file name must not be empty, "." or ".."');
        }

        if (preg_match('/[\x00-\x1F\x7F<>:"\/\\\\|?*]/', $fileName) === 1) {
            throw new InvalidExcelExportException(sprintf(
                'Excel file name [%s] must be a plain file name without path separators, control characters or <>:"/\\|?*',
                $fileName
            ));
        }

        if (mb_strlen($fileName) > self::MAX_FILE_NAME_LENGTH) {
            throw new InvalidExcelExportException(sprintf(
                'Excel file name exceeds %d characters',
                self::MAX_FILE_NAME_LENGTH
            ));
        }

        if (!str_ends_with(strtolower($fileName), '.xlsx')) {
            $fileName .= '.xlsx';
        }

        return $fileName;
    }

    /**
     * @return array<string, int|float>
     */
    public static function normalizeColumnWidths(array $widths): array
    {
        $normalized = [];
        foreach ($widths as $range => $width) {
            if (!is_string($range) || preg_match('/^([A-Z]{1,3})(?::([A-Z]{1,3}))?$/', $range, $matches) !== 1) {
                throw new InvalidExcelExportException(sprintf(
                    'Excel column range [%s] must look like A, C or B:D',
                    is_string($range) ? $range : get_debug_type($range)
                ));
            }

            try {
                $from = ColumnLetter::toIndex($matches[1]);
                $to = isset($matches[2]) && $matches[2] !== ''
                    ? ColumnLetter::toIndex($matches[2])
                    : $from;
            } catch (OutOfRangeException $e) {
                throw new InvalidExcelExportException(sprintf(
                    'Excel column range [%s] exceeds the limit of %d columns',
                    $range,
                    self::MAX_HEADER_COLUMNS
                ), 0, $e);
            }

            if ($from > $to) {
                throw new InvalidExcelExportException(sprintf(
                    'Excel column range [%s] must start before it ends',
                    $range
                ));
            }

            // xlswriter 2.0.2 的 setColumn() 不接受单列写法 A，内部统一转成 A:A
            $range = isset($matches[2]) && $matches[2] !== ''
                ? $range
                : $matches[1] . ':' . $matches[1];

            if ((!is_int($width) && !is_float($width)) || !is_finite((float) $width)) {
                throw new InvalidExcelExportException(sprintf(
                    'Excel column width for [%s] must be a finite number, %s given',
                    $range,
                    get_debug_type($width)
                ));
            }

            if ($width < 0.1 || $width > 255) {
                throw new InvalidExcelExportException(sprintf(
                    'Excel column width for [%s] must be between 0.1 and 255 (%s given)',
                    $range,
                    (string) $width
                ));
            }

            $normalized[$range] = $width;
        }

        return $normalized;
    }

    public static function normalizeBatchSize(int $batchSize): int
    {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidExcelExportException(sprintf(
                'Excel batch size must be between 1 and %d (%d given)',
                self::MAX_BATCH_SIZE,
                $batchSize
            ));
        }

        return $batchSize;
    }

    public static function normalizeHeaderRowCount(int $rowCount): int
    {
        if ($rowCount < 0 || $rowCount > self::MAX_HEADER_ROWS) {
            throw new InvalidExcelExportException(sprintf(
                'writeHeader() must return 0 to %d header rows (%d given)',
                self::MAX_HEADER_ROWS,
                $rowCount
            ));
        }

        return $rowCount;
    }

    public static function normalizeDataRowCount(int $rowCount, int $headerRowCount): int
    {
        if ($rowCount < 0 || $rowCount > self::MAX_DATA_ROWS) {
            throw new InvalidExcelExportException(sprintf(
                'writeRows() must return 0 to %d data rows (%d given)',
                self::MAX_DATA_ROWS,
                $rowCount
            ));
        }

        if ($rowCount + $headerRowCount > self::EXCEL_MAX_ROWS) {
            throw new InvalidExcelExportException(sprintf(
                'Excel sheet exceeds %d rows (%d header rows + %d data rows)',
                self::EXCEL_MAX_ROWS,
                $headerRowCount,
                $rowCount
            ));
        }

        return $rowCount;
    }

    public static function normalizeFreezeRowCount(int $rowCount, int $headerRowCount): int
    {
        if ($headerRowCount === 0) {
            return 0;
        }

        if ($rowCount < 0 || $rowCount > $headerRowCount) {
            throw new InvalidExcelExportException(sprintf(
                'freezeRowCount() must return 0 to %d rows for the current header (%d given)',
                $headerRowCount,
                $rowCount
            ));
        }

        return $rowCount;
    }

    public static function normalizeAutoFilterRange(?string $range): ?string
    {
        if ($range === null) {
            return null;
        }

        $range = trim($range);
        if ($range === '') {
            throw new InvalidExcelExportException('autoFilterRange() must return null or a non-empty range');
        }

        return $range;
    }

    /**
     * @param list<string> $headers
     * @return list<bool|float|int|string>
     */
    public static function normalizeRow(array $row, int $rowNumber, array $headers, string $dateFormat): array
    {
        if (!array_is_list($row)) {
            throw new InvalidExcelRowException(
                sprintf('Excel row %d must be a list, %s given', $rowNumber, get_debug_type($row)),
                $rowNumber
            );
        }

        $expected = count($headers);
        $actual = count($row);
        if ($actual !== $expected) {
            throw new InvalidExcelRowException(sprintf(
                'Excel row %d has %d columns, expected %d',
                $rowNumber,
                $actual,
                $expected
            ), $rowNumber);
        }

        $normalized = [];
        foreach ($row as $index => $value) {
            $columnIndex = $index + 1;
            $columnLetter = ColumnLetter::fromIndex($columnIndex);

            if ($value === null) {
                $normalized[] = '';
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $normalized[] = $value;
                continue;
            }

            if (is_string($value)) {
                $text = $value;
            } elseif ($value instanceof DateTimeInterface) {
                $text = $value->format($dateFormat);
            } elseif ($value instanceof Stringable) {
                $text = (string) $value;
            } else {
                throw new InvalidExcelRowException(sprintf(
                    'Excel row %d column %d (%s) has unsupported type %s',
                    $rowNumber,
                    $columnIndex,
                    $columnLetter,
                    get_debug_type($value)
                ), $rowNumber, $columnIndex, $columnLetter);
            }

            if (mb_strlen($text) > self::MAX_CELL_LENGTH) {
                throw new InvalidExcelRowException(sprintf(
                    'Excel row %d column %d (%s) exceeds %d characters',
                    $rowNumber,
                    $columnIndex,
                    $columnLetter,
                    self::MAX_CELL_LENGTH
                ), $rowNumber, $columnIndex, $columnLetter);
            }

            $normalized[] = $text;
        }

        return $normalized;
    }
}
