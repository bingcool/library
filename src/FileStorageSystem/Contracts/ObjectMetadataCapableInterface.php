<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Contracts;

use Swoolefy\Library\FileStorageSystem\ValueObject\ObjectMetadata;

/**
 * 对象元数据读取能力（Head/stat 语义）。
 */
interface ObjectMetadataCapableInterface
{
    /** 获取完整元数据。 */
    public function getMetadata(string $path): ObjectMetadata;

    /** 最后修改 Unix 秒。 */
    public function lastModified(string $path): int;

    /** 与 lastModified 同义。 */
    public function getTimestamp(string $path): int;

    /** 对象字节大小。 */
    public function fileSize(string $path): int;

    /** MIME 类型。 */
    public function mimeType(string $path): string;
}
