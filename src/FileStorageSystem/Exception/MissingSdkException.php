<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Exception;

/**
 * 所需 Composer SDK / 官方类未安装。
 *
 * 适配器在构造时检测官方入口类；缺失时抛本异常并提示 `composer require`。
 */
final class MissingSdkException extends FileStorageException
{
    /**
     * @param string $package Composer 包名，如 aws/aws-sdk-php
     * @param string $driver 驱动名，如 aws_s3
     * @param class-string|null $class 缺失的官方类 FQCN
     */
    public static function missingComposerPackage(string $package, string $driver, ?string $class = null): self
    {
        $classHint = $class !== null
            ? sprintf(' Official class [%s] was not found.', $class)
            : '';

        return new self(sprintf(
            'FileStorage driver [%s] requires Composer package [%s].%s Please install it via: composer require %s',
            $driver,
            $package,
            $classHint,
            $package
        ));
    }
}
