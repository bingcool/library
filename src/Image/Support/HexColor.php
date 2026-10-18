<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Support;

use Swoolefy\Library\Image\Exception\InvalidImageException;

/** #RGB / #RRGGBB 转 RGB 三元组，供 Bacon Fill 等跨模块复用 */
final class HexColor
{
    /**
     * @return array{int, int, int} 0–255
     *
     * @throws InvalidImageException 非法 hex
     */
    public static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new InvalidImageException('Invalid hex color');
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
