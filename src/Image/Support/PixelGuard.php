<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Support;

use Swoolefy\Library\Image\Exception\ImageTooLargeException;
use Swoolefy\Library\Image\Exception\InvalidImageException;

/**
 * 解码前像素上限校验，避免大图占满 Worker 内存。
 */
final class PixelGuard
{
    /**
     * @param int $maxPixels 允许的最大宽×高；0 表示不校验
     *
     * @throws ImageTooLargeException 超限
     * @throws InvalidImageException 宽高非法
     */
    public static function assertWithinLimit(int $width, int $height, int $maxPixels): void
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidImageException('Image dimensions are invalid');
        }

        if ($maxPixels > 0 && ($width * $height) > $maxPixels) {
            throw new ImageTooLargeException(sprintf(
                'Image exceeds max_pixels (%d): %dx%d',
                $maxPixels,
                $width,
                $height
            ));
        }
    }

    /**
     * 对磁盘文件用 getimagesize 估算像素，再 {@see assertWithinLimit()}。
     *
     * @throws InvalidImageException 非图片或不可读
     * @throws ImageTooLargeException 超限
     */
    public static function assertFilePath(string $path, int $maxPixels): void
    {
        if ($path === '' || !is_file($path)) {
            throw new InvalidImageException('Image file is not readable');
        }

        $info = @getimagesize($path);
        if ($info === false) {
            throw new InvalidImageException('Input is not a valid image');
        }

        self::assertWithinLimit((int) $info[0], (int) $info[1], $maxPixels);
    }
}
