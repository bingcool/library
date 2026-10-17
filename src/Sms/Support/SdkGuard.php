<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Support;

use Swoolefy\Library\Sms\Exception\MissingSdkException;

/**
 * 可选厂商 SDK 的加载检测。
 *
 * 阿里云、腾讯云已通过 Composer require 安装，对应网关直接 new 官方类，不经过这里。
 * 以后新增厂商且其 SDK 不作为本库硬依赖时，在该网关构造函数里调用 requireClass()。
 */
final class SdkGuard
{
    /**
     * 官方类无法 autoload 时抛 MissingSdkException，并提示 composer require。
     *
     * @param class-string $class 官方 SDK 入口类
     * @param string $package Composer 包名
     * @param string $gateway 网关名
     */
    public static function requireClass(string $class, string $package, string $gateway): void
    {
        if (!class_exists($class)) {
            throw MissingSdkException::missingComposerPackage($package, $gateway, $class);
        }
    }
}
