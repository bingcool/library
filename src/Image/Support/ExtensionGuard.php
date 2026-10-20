<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Support;

use Swoolefy\Library\Image\Exception\InvalidConfigException;
use Swoolefy\Library\Image\Exception\MissingExtensionException;

/**
 * 检测 PHP 图像扩展是否满足配置的 driver。不检测 Composer 包（intervention/image 为硬依赖）。
 */
final class ExtensionGuard
{
    /** @throws MissingExtensionException */
    public static function assertGd(): void
    {
        if (!extension_loaded('gd')) {
            throw new MissingExtensionException('PHP extension gd is required for image driver [gd]');
        }
    }

    /** @throws MissingExtensionException */
    public static function assertImagick(): void
    {
        if (!extension_loaded('imagick')) {
            throw new MissingExtensionException('PHP extension imagick is required for image driver [imagick]');
        }
    }

    /**
     * @param string $driver gd|imagick
     *
     * @throws MissingExtensionException 扩展缺失
     * @throws InvalidConfigException 未知 driver
     */
    public static function assertDriver(string $driver): void
    {
        match (strtolower(trim($driver))) {
            'gd' => self::assertGd(),
            'imagick' => self::assertImagick(),
            default => throw new InvalidConfigException(sprintf(
                'Unknown image driver [%s]; expected gd|imagick',
                $driver
            )),
        };
    }
}
