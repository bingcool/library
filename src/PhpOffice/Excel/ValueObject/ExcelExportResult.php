<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\ValueObject;

/**
 * 已成功落盘的 Excel 文件描述，不含二进制内容。
 */
final readonly class ExcelExportResult
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        /** 绝对路径（realpath 后） */
        public string $path,
        /** 含 .xlsx 后缀的文件名 */
        public string $fileName,
        public string $sheetName,
        /** 数据行数，不含表头与合计行 */
        public int $rowCount,
        /** 文件字节数 */
        public int $size,
    ) {
    }
}
