<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\Support;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\RendererStyle\Fill;
use Swoolefy\Library\Image\Support\HexColor;

/** 将 Builder 的 hex 前景/背景转为 Bacon {@see Fill} */
final class FillFactory
{
    /**
     * @param array{0: string, 1: string} $colors [background, foreground]
     */
    public static function fromHex(array $colors): Fill
    {
        [$bg, $fg] = array_pad($colors, 2, '#ffffff');
        [$br, $bgG, $bb] = HexColor::rgb((string) $bg);
        [$fr, $fgG, $fb] = HexColor::rgb((string) ($fg ?: '#000000'));

        return Fill::uniformColor(new Rgb($br, $bgG, $bb), new Rgb($fr, $fgG, $fb));
    }
}
