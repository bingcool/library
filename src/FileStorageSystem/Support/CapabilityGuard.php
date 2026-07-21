<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Support;

use Swoolefy\Library\FileStorageSystem\Exception\UnsupportedCapabilityException;

/**
 * FileDisk 可选 Capability 的运行时校验：未注入时抛出明确异常而非空指针。
 */
final class CapabilityGuard
{
    /**
     * 要求 Capability 已注入，否则抛出 UnsupportedCapabilityException。
     *
     * @template T of object
     * @param T|null $capability 可选能力实现
     * @param string $name 能力简称（如 metadata、multipart），用于异常消息
     * @return T
     */
    public static function require(?object $capability, string $name): object
    {
        if ($capability === null) {
            throw new UnsupportedCapabilityException($name);
        }

        return $capability;
    }
}
