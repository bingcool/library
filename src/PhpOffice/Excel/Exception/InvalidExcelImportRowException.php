<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\Exception;

/**
 * Excel 导入数据行不合法；行列号均为 1 基。
 */
final class InvalidExcelImportRowException extends InvalidExcelImportException
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
