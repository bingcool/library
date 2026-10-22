<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\ValueObject;

/**
 * 只读读取结果；rows 为全部数据行，适合小文件或确知行数的场景。
 */
final readonly class ExcelReadResult
{
    /**
     * @param list<string> $headers 表头文本；headerRow=0 时为空
     * @param int $headerRowNumber 表头所在 Excel 行号（1 基）；0 表示无表头
     * @param list<ExcelImportRow> $rows 全部数据行
     * @param int $rowCount 数据行数，等于 count($rows)
     * @param int $skippedRowCount 因空行被跳过的行数
     * @param int $lastRowNumber 最后读取到的 Excel 行号（1 基）
     */
    public function __construct(
        public string $path,
        public string $sheetName,
        public array $headers,
        public int $headerRowNumber,
        public array $rows,
        public int $rowCount,
        public int $skippedRowCount,
        public int $lastRowNumber,
    ) {
    }
}
