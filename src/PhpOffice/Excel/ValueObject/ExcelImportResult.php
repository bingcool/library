<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\ValueObject;

/**
 * 只读导入结果。
 */
final readonly class ExcelImportResult
{
    /**
     * @param list<string> $headers 表头文本；headerRow=0 时为空
     * @param int $headerRowNumber 表头所在 Excel 行号（1 基）；0 表示无表头
     * @param int $rowCount 交给 handleRow() 的数据行数
     * @param int $skippedRowCount 因空行被跳过的行数
     * @param int $lastRowNumber 最后读取到的 Excel 行号（1 基）
     */
    public function __construct(
        public string $path,
        public string $sheetName,
        public array $headers,
        public int $headerRowNumber,
        public int $rowCount,
        public int $skippedRowCount,
        public int $lastRowNumber,
    ) {
    }
}
