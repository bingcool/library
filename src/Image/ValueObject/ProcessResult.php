<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\ValueObject;

/**
 * 编码成功结果。不含原始路径或 Intervention 句柄，可安全返回给 Controller / 存储层。
 */
final class ProcessResult
{
    public function __construct(
        /** 编码后的完整二进制 */
        public readonly string $binary,
        /** MIME，如 image/jpeg */
        public readonly string $mime,
        /** 文件扩展名（jpeg 归一化为 jpg） */
        public readonly string $extension,
        /** binary 字节长度 */
        public readonly int $bytes,
        /** 编码完成时的画布尺寸 */
        public readonly ImageSize $size,
    ) {
    }
}
