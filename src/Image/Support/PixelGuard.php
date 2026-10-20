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
    /** 流预检最多读取的头部字节数；JPEG 的 SOF 可能排在大段 EXIF 之后，留足够余量 */
    private const HEADER_BYTES = 1_048_576;

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

    /**
     * 对内存中的图片二进制做解码前预检。
     *
     * `getimagesizefromstring()` 只解析文件头，不会把整张图解码进内存，
     * 因此可以在压缩炸弹/超大图耗尽 Worker 内存之前就拦截。
     *
     * @throws InvalidImageException 空串或不是有效图片
     * @throws ImageTooLargeException 超限
     */
    public static function assertBinary(string $binary, int $maxPixels): void
    {
        if ($binary === '') {
            throw new InvalidImageException('Image binary is empty');
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new InvalidImageException('Input is not a valid image');
        }

        self::assertWithinLimit((int) $info[0], (int) $info[1], $maxPixels);
    }

    /**
     * 对流做解码前预检。流必须可定位，并会先 rewind 到起点，避免调用方指针位置
     * 影响预检/解码结果；随后交给 decoder 时由 decoder 再次 rewind。
     *
     * 不可定位的流先交给 {@see bufferedStream()} 复制到临时流。
     *
     * @param resource $stream 已打开的图片数据流
     *
     * @throws InvalidImageException 非资源、不可定位/不可 rewind 或不是有效图片
     * @throws ImageTooLargeException 超限
     */
    public static function assertStream(mixed $stream, int $maxPixels): void
    {
        if (!is_resource($stream)) {
            throw new InvalidImageException('Image stream is not readable');
        }

        $meta = stream_get_meta_data($stream);
        if (empty($meta['seekable'])) {
            throw new InvalidImageException('Image stream must be seekable for pixel pre-check');
        }

        if (rewind($stream) === false) {
            throw new InvalidImageException('Unable to rewind image stream');
        }

        $header = (string) stream_get_contents($stream, self::HEADER_BYTES);

        self::assertBinary($header, $maxPixels);
    }

    /**
     * 返回可定位的流：原流可定位时先 rewind 再原样返回，否则从当前位置复制到 `php://temp`。
     *
     * 可定位流必须从起点解码，否则调用方指针停在中间会导致预检读到错误头部；
     * 不可定位流无法回退，只能从当前位置开始复制。
     *
     * 复制到临时流而不是读入字符串，避免大文件全部驻留内存；
     * 调用方若拿到的是新流，应在使用后自行关闭。
     *
     * @param resource $stream
     *
     * @return resource
     *
     * @throws InvalidImageException 非资源、不可 rewind 或复制失败
     */
    public static function bufferedStream(mixed $stream): mixed
    {
        if (!is_resource($stream)) {
            throw new InvalidImageException('Image stream is not readable');
        }

        $meta = stream_get_meta_data($stream);
        if (!empty($meta['seekable'])) {
            if (rewind($stream) === false) {
                throw new InvalidImageException('Unable to rewind image stream');
            }

            return $stream;
        }

        $copy = fopen('php://temp', 'r+b');
        if ($copy === false) {
            throw new InvalidImageException('Unable to allocate buffer for image stream');
        }

        if (stream_copy_to_stream($stream, $copy) === false) {
            fclose($copy);

            throw new InvalidImageException('Unable to buffer image stream');
        }

        rewind($copy);

        return $copy;
    }
}
