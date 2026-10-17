<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Exception;

/**
 * 可选厂商的 Composer SDK 未安装。
 *
 * 阿里云、腾讯云已是本库依赖，不走本异常。以后新增、且不打进 require 的厂商（如百度），
 * 在其网关构造时用 SdkGuard 检测；缺包时抛本异常并提示 `composer require`。
 */
final class MissingSdkException extends SmsException
{
    /**
     * @param string $package Composer 包名
     * @param string $gateway 网关名，如 baidu
     * @param class-string|null $class 缺失的官方类
     */
    public static function missingComposerPackage(string $package, string $gateway, ?string $class = null): self
    {
        $classHint = $class !== null
            ? sprintf(' Official class [%s] was not found.', $class)
            : '';

        return new self(sprintf(
            'Sms gateway [%s] requires Composer package [%s].%s Please install it via: composer require %s',
            $gateway,
            $package,
            $classHint,
            $package
        ));
    }
}
