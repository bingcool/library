<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode\Renderer;

use Picqer\Barcode\Barcode;
use Picqer\Barcode\Renderers\PngRenderer;
use Picqer\Barcode\Renderers\SvgRenderer;
use Swoolefy\Library\Barcode\Support\ExtensionGuard;
use Swoolefy\Library\Barcode\ValueObject\BarcodeOptions;

/**
 * 调用 picqer v3 {@see SvgRenderer} / {@see PngRenderer} 输出最终字符串或 PNG 二进制。
 *
 * 宽度由 {@see resolveWidth()} 统一解析，避免 Builder 与渲染器重复逻辑。
 */
final class BarcodeRenderer
{
    /**
     * @return string SVG XML
     */
    public static function svg(Barcode $barcode, BarcodeOptions $options): string
    {
        $width = self::resolveWidth($barcode, $options);

        return (new SvgRenderer())->render($barcode, $width, (float) $options->height);
    }

    /**
     * @return string PNG 原始字节
     *
     * @throws \Swoolefy\Library\Barcode\Exception\MissingExtensionException
     */
    public static function png(Barcode $barcode, BarcodeOptions $options): string
    {
        ExtensionGuard::assertGdOrImagick();
        $width = self::resolveWidth($barcode, $options);
        $renderer = new PngRenderer();
        if (extension_loaded('gd')) {
            $renderer->useGd();
        }

        return $renderer->render($barcode, $width, (float) $options->height);
    }

    /**
     * 计算传给 picqer 的总宽度：显式 width &gt; 0 优先，否则 max(200, intrinsic×2)。
     */
    private static function resolveWidth(Barcode $barcode, BarcodeOptions $options): float
    {
        if ($options->width > 0) {
            return (float) $options->width;
        }

        return (float) max(200, $barcode->getWidth() * 2);
    }
}
