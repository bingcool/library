<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Exception;

/** FileDisk 调用了未注入的 Capability。 */
final class UnsupportedCapabilityException extends FileStorageException
{
    /**
     * @param string $capability 未注入的能力名（如 metadata、multipart）
     * @param \Throwable|null $previous
     */
    public function __construct(string $capability, ?\Throwable $previous = null)
    {
        parent::__construct("Unsupported capability: {$capability}", 0, $previous);
    }
}
