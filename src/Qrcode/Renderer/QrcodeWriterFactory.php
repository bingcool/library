<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\Renderer;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\EpsImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Swoolefy\Library\Qrcode\Support\ExtensionGuard;
use Swoolefy\Library\Qrcode\Support\FillFactory;
use Swoolefy\Library\Qrcode\ValueObject\QrcodeOptions;

/**
 * 根据 {@see QrcodeOptions} 装配 Bacon {@see Writer} + 对应 Renderer。
 */
final class QrcodeWriterFactory
{
    /**
     * GD 栅格 PNG Writer（size、margin、Fill 来自 options）。
     *
     * @throws \Swoolefy\Library\Qrcode\Exception\MissingExtensionException
     */
    public static function png(QrcodeOptions $options): Writer
    {
        ExtensionGuard::assertGd();

        return new Writer(new GDLibRenderer(
            $options->size,
            $options->margin,
            'png',
            9,
            FillFactory::fromHex($options->fill),
        ));
    }

    /**
     * 矢量 SVG Writer（ImageRenderer + SvgImageBackEnd）。
     *
     * @throws \Swoolefy\Library\Qrcode\Exception\MissingExtensionException
     */
    public static function svg(QrcodeOptions $options): Writer
    {
        ExtensionGuard::assertXmlWriter();

        return new Writer(new ImageRenderer(
            new RendererStyle(
                $options->size,
                $options->margin,
                fill: FillFactory::fromHex($options->fill),
            ),
            new SvgImageBackEnd(),
        ));
    }

    /**
     * EPS Writer（ImageRenderer + EpsImageBackEnd）。
     *
     * @throws \Swoolefy\Library\Qrcode\Exception\MissingExtensionException
     */
    public static function eps(QrcodeOptions $options): Writer
    {
        ExtensionGuard::assertXmlWriter();

        return new Writer(new ImageRenderer(
            new RendererStyle(
                $options->size,
                $options->margin,
                fill: FillFactory::fromHex($options->fill),
            ),
            new EpsImageBackEnd(),
        ));
    }
}
