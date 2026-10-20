<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Support;

use Swoolefy\Library\Image\Exception\InvalidConfigException;
use Swoolefy\Library\Image\Exception\UnsupportedFormatException;

/**
 * 输出格式与 driver 能力协商。jpg 与 jpeg 归一化为 jpeg。
 *
 * 白名单只收录**有对应 encoder** 的格式：没有 encoder 的格式应直接抛
 * {@see UnsupportedFormatException}，而不是落入 JpegEncoder 后产生
 * 「extension=bmp、magic=jpeg」这类元信息不一致。
 */
final class FormatNegotiator
{
    /** @var list<string> */
    private const GD = ['jpeg', 'jpg', 'png', 'webp', 'gif'];

    /** @var list<string> */
    private const IMAGICK = ['jpeg', 'jpg', 'png', 'webp', 'gif', 'avif'];

    /** GD 编译能力对应的 `gd_info()` 键 */
    private const GD_INFO_KEYS = [
        'jpeg' => 'JPEG Support',
        'png' => 'PNG Support',
        'webp' => 'WebP Support',
        'gif' => 'GIF Create Support',
        'avif' => 'AVIF Support',
    ];

    /** 归一化格式对应的 Imagick 格式名 */
    private const IMAGICK_FORMATS = [
        'jpeg' => 'JPEG',
        'png' => 'PNG',
        'webp' => 'WEBP',
        'gif' => 'GIF',
        'avif' => 'AVIF',
        'tiff' => 'TIFF',
        'bmp' => 'BMP',
    ];

    public static function normalize(string $format): string
    {
        $format = strtolower(trim($format));

        return $format === 'jpg' ? 'jpeg' : $format;
    }

    /**
     * 该 driver 当前**运行时**可编码的格式名（已归一化）。
     *
     * 静态白名单只收录有 encoder 的格式；这里再按 `gd_info()` / `Imagick::queryFormats()`
     * 过滤掉当前 PHP 未编译对应能力的格式（例如未启用 WebP 的 GD）。
     *
     * @return list<string>
     *
     * @throws InvalidConfigException 未知 driver
     */
    public static function supported(string $driver): array
    {
        $driver = strtolower(trim($driver));
        $base = match ($driver) {
            'gd' => self::GD,
            'imagick' => self::IMAGICK,
            default => throw new InvalidConfigException(sprintf(
                'Unknown image driver [%s]; expected gd|imagick',
                $driver
            )),
        };

        return array_values(array_filter(
            $base,
            static fn (string $format): bool => self::runtimeSupports($driver, $format),
        ));
    }

    /**
     * 查询 PHP 图像扩展是否真的具备某格式的编解码能力。
     *
     * @throws InvalidConfigException 未知 driver
     */
    public static function runtimeSupports(string $driver, string $format): bool
    {
        $driver = strtolower(trim($driver));
        $format = self::normalize($format);

        if ($driver === 'gd') {
            if (!function_exists('gd_info')) {
                return false;
            }

            $key = self::GD_INFO_KEYS[$format] ?? null;

            return $key !== null && (bool) (gd_info()[$key] ?? false);
        }

        if ($driver === 'imagick') {
            if (!class_exists(\Imagick::class)) {
                return false;
            }

            $name = self::IMAGICK_FORMATS[$format] ?? null;
            if ($name === null) {
                return false;
            }

            try {
                $formats = \Imagick::queryFormats();
            } catch (\Throwable) {
                return false;
            }

            return in_array($name, array_map('strtoupper', $formats), true);
        }

        throw new InvalidConfigException(sprintf(
            'Unknown image driver [%s]; expected gd|imagick',
            $driver
        ));
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
