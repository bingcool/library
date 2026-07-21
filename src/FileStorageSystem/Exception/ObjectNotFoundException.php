<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Exception;

/** 对象不存在（路径或 SDK 404/NoSuchKey）。 */
final class ObjectNotFoundException extends FileStorageException
{
    /**
     * @param string $path 对象 key
     * @param \Throwable|null $previous 底层 SDK 异常
     */
    public function __construct(string $path, ?\Throwable $previous = null)
    {
        parent::__construct("Object not found: {$path}", 0, $previous);
    }
}
