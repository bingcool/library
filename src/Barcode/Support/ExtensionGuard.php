<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode\Support;

use Swoolefy\Library\Barcode\Exception\MissingExtensionException;

/** PNG 输出前检测 gd / imagick 扩展（picqer PngRenderer 依赖其一） */
final class ExtensionGuard
{
    /**
     * @throws MissingExtensionException 两者均未加载
     */
    public static function assertGdOrImagick(): void
    {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            throw new MissingExtensionException('PHP extension gd or imagick is required for barcode PNG output');
        }
    }
}
