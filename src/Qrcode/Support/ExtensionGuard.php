<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\Support;

use Swoolefy\Library\Qrcode\Exception\MissingExtensionException;

/** 按输出格式检测 Bacon 渲染所需的 PHP 扩展 */
final class ExtensionGuard
{
    /** @throws MissingExtensionException ext-gd 未加载 */
    public static function assertGd(): void
    {
        if (!extension_loaded('gd')) {
            throw new MissingExtensionException('PHP extension gd is required for QR PNG output');
        }
    }

    /** @throws MissingExtensionException ext-xmlwriter 未加载 */
    public static function assertXmlWriter(): void
    {
        if (!extension_loaded('xmlwriter')) {
            throw new MissingExtensionException('PHP extension xmlwriter is required for QR SVG/EPS output');
        }
    }
}
