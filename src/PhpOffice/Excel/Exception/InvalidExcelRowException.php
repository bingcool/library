<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\Exception;

/**
 * 某行数据不合法：不是 list、列数不一致、单元格类型不支持或文本超长。
 *
 * 行号从 1 开始计（仅数据行，不含表头）；列号从 1 开始计。
 * 整行级错误（不是 list / 列数不一致）不指向具体列，此时 columnIndex 为 0。
 */
final class InvalidExcelRowException extends InvalidExcelExportException
{
    public function __construct(
        string $message,
        public readonly int $rowNumber,
        public readonly int $columnIndex = 0,
        public readonly ?string $columnLetter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
