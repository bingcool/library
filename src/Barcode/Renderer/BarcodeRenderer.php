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
     *
     * showText 为真时，条状只绘制在顶部区域，底部留白带叠加人类可读文字；
     * 为假时保持 picqer 原生输出（条状填满整个高度，无文字）。
     */
    public static function svg(Barcode $barcode, BarcodeOptions $options): string
    {
        $width = self::resolveWidth($barcode, $options);

        if (! $options->showText) {
            return (new SvgRenderer())->render($barcode, $width, (float) $options->height);
        }

        $totalHeight = max(1, $options->height);
        $fontSize    = (int) round(max(8, $totalHeight * 0.12));
        $textArea    = (int) round($fontSize * 1.4);
        $barHeight   = max(1, $totalHeight - $textArea);

        // 条状先按 barHeight 渲染填满顶部，再把画布高度撑回 totalHeight 以腾出底部文字带
        $svg = (new SvgRenderer())->render($barcode, $width, (float) $barHeight);

        $svg = preg_replace(
            '#<svg width="([0-9.]+)" height="[0-9.]+" viewBox="0 0 ([0-9.]+) [0-9.]+"#',
            '<svg width="$1" height="' . $totalHeight . '" viewBox="0 0 $2 ' . $totalHeight . '"',
            $svg,
            1
        ) ?? $svg;

        // 在底部留白带内垂直居中（基线略低于中线以适配数字字形）
        $baseline = round($totalHeight - $textArea / 2 + $fontSize * 0.35, 2);
        $textNode = sprintf(
            "\t" . '<text x="%s" y="%s" font-family="monospace" font-size="%d" fill="#000000" text-anchor="middle">%s</text>' . PHP_EOL,
            round($width / 2, 2),
            $baseline,
            $fontSize,
            htmlspecialchars($options->content, ENT_QUOTES | ENT_XML1, 'UTF-8')
        );

        return str_replace('</svg>', $textNode . '</svg>', $svg);
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
