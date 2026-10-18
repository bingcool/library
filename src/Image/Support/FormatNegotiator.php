<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Support;

use Swoolefy\Library\Image\Exception\UnsupportedFormatException;

/**
 * 输出格式与 driver 能力协商。jpg 与 jpeg 归一化为 jpeg。
 */
final class FormatNegotiator
{
    /** @var list<string> */
    private const GD = ['jpeg', 'jpg', 'png', 'webp', 'gif'];

    /** @var list<string> */
    private const IMAGICK = ['jpeg', 'jpg', 'png', 'webp', 'gif', 'avif', 'tiff', 'bmp'];

    public static function normalize(string $format): string
    {
        $format = strtolower(trim($format));

        return $format === 'jpg' ? 'jpeg' : $format;
    }

    /**
     * @return list<string> 该 driver 可编码的格式名（已归一化）
     */
    public static function supported(string $driver): array
    {
        return strtolower($driver) === 'imagick' ? self::IMAGICK : self::GD;
    }

    /**
     * @return string 归一化后的 format
     *
     * @throws UnsupportedFormatException
     */
    public static function assertSupported(string $driver, string $format): string
    {
        $format = self::normalize($format);
        $allowed = self::supported($driver);
        if (!in_array($format, $allowed, true)) {
            throw new UnsupportedFormatException(sprintf(
                'Image format [%s] is not supported by driver [%s]; allowed: %s',
                $format,
                $driver,
                implode(', ', $allowed)
            ));
        }

        return $format;
    }
}
