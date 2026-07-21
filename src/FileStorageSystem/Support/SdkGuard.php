<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Support;

use Swoolefy\Library\FileStorageSystem\Exception\MissingSdkException;

/**
 * 检测官方 SDK 入口类是否可由 Composer autoload 加载。
 */
final class SdkGuard
{
    /**
     * 若官方类不存在则抛 MissingSdkException，提示 composer require。
     *
     * @param class-string $class 官方 SDK 入口类 FQCN
     * @param string $package Composer 包名
     * @param string $driver FileStorage driver 名
     */
    public static function requireClass(string $class, string $package, string $driver): void
    {
        // class_exists 会尝试 autoload；包未安装时返回 false，避免后续 new 出 Class not found
        if (!class_exists($class)) {
            throw MissingSdkException::missingComposerPackage($package, $driver, $class);
        }
    }
}
