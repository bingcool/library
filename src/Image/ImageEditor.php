<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image;

use Intervention\Image\Alignment;
use Intervention\Image\Colors\Rgb\Color;
use Intervention\Image\Direction;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager as InterventionImageManager;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Swoolefy\Library\Image\Config\ImageConfig;
use Swoolefy\Library\Image\Config\ImagePresetConfig;
use Swoolefy\Library\Image\Exception\ImageException;
use Swoolefy\Library\Image\Exception\ImageTooLargeException;
use Swoolefy\Library\Image\Exception\InvalidImageException;
use Swoolefy\Library\Image\Exception\UnsupportedFormatException;
use Swoolefy\Library\Image\Support\FormatNegotiator;
use Swoolefy\Library\Image\Support\PixelGuard;
use Swoolefy\Library\Image\Support\PositionAnchor;
use Swoolefy\Library\Image\Support\SizeCalculator;
use Swoolefy\Library\Image\ValueObject\EncodeOptions;
use Swoolefy\Library\Image\ValueObject\ImageSize;
use Swoolefy\Library\Image\ValueObject\ProcessResult;
use Swoolefy\Library\Image\Watermark\ImageWatermark;
use Swoolefy\Library\Image\Watermark\TextWatermark;

/**
 * 单次图像编辑链。包装 Intervention {@see ImageInterface}，对外链式 API。
 *
 * Intervention v4 的修改方法多为**就地修改**当前实例（返回的新 ImageInterface 仍代表同一条编辑链）。
 * 从同一原图派生多档尺寸时，应对原路径多次 {@see ImageManager::open()}，不要复用已 applyPreset 的 Editor。
 */
final class ImageEditor
{
    /** {@see applyPreset()} 附带的编码默认值，在 {@see encode()} 时与全局 encode 配置合并 */
    private ?EncodeOptions $presetEncode = null;

    /**
     * @param ImageInterface $image 当前编辑中的 Intervention 图像
     * @param ImageConfig $config 全局 image 配置（driver、encode 默认、presets）
     * @param InterventionImageManager $manager 用于 decode 叠加图等内部操作
     */
    public function __construct(
        private ImageInterface $image,
        private ImageConfig $config,
        private InterventionImageManager $manager,
    ) {
    }

    /**
     * 按模式缩放/适应目标框。
     *
     * 各模式语义（与 v4 原生方法对应）：
     * - contain：等比缩进框内，**只缩不放、不补白**，用 `scaleDown()`；小图保持原尺寸
     * - cover：等比铺满后居中裁剪，宽高都必须 > 0
     * - scale：等比缩放（允许放大），单维时按该维等比
     * - stretch/fill：双维时强制拉到指定宽高；单维时退化为等比缩放
     *
     * `null`/`0`/负数表示该维不限制；两维都不限制或 mode 未知时抛异常。
     *
     * @param int|null $w 目标宽；null/0/负数表示不限制
     * @param int|null $h 目标高；null/0/负数表示不限制
     * @param string $mode contain|cover|scale|stretch|fill
     *
     * @throws InvalidImageException 参数组合非法或 mode 未知
     * @throws ImageTooLargeException 目标尺寸超过 max_pixels
     */
    public function resize(?int $w, ?int $h, string $mode = 'contain'): self
    {
        $mode = strtolower(trim($mode));
        $width = ($w !== null && $w > 0) ? $w : null;
        $height = ($h !== null && $h > 0) ? $h : null;

        if ($width === null && $height === null) {
            throw new InvalidImageException(sprintf(
                'resize() requires at least one positive dimension (mode: %s)',
                $mode
            ));
        }

        if ($mode === 'contain') {
            // v4 的 contain() 是「固定画布 + 补白」，与本模块「等比、不补白」语义不同。
            // 先用纯函数算出只缩不放的目标尺寸，再交给 scaleDown() 做像素采样。
            $target = SizeCalculator::contain(
                $this->image->width(),
                $this->image->height(),
                $width ?? 0,
                $height ?? 0,
            );
            $this->image = $this->image->scaleDown($target['width'], $target['height']);

            return $this;
        }

        if ($mode === 'cover') {
            if ($width === null || $height === null) {
                throw new InvalidImageException('cover mode requires both width and height');
            }

            $this->assertCoverTarget($width, $height);
            $this->image = $this->image->cover($width, $height);

            return $this;
        }

        if (in_array($mode, ['scale', 'stretch', 'fill'], true)) {
            $this->assertScaleTarget($width, $height);
            $this->image = ($mode === 'scale' || $width === null || $height === null)
                ? $this->image->scale($width, $height)
                : $this->image->resize($width, $height);

            return $this;
        }

        throw new InvalidImageException(sprintf(
            'Unknown resize mode [%s]; expected contain|cover|scale|stretch|fill',
            $mode
        ));
    }

    /**
     * 只缩小不放大：宽高均不超过给定上限。
     *
     * `0`/负数表示该维不限制，但至少一维必须为正数。
     *
     * @throws InvalidImageException 两维都未给出正数上限
     */
    public function thumbnail(int $maxW, int $maxH): self
    {
        $maxW = $maxW > 0 ? $maxW : null;
        $maxH = $maxH > 0 ? $maxH : null;
        if ($maxW === null && $maxH === null) {
            throw new InvalidImageException('thumbnail() requires at least one positive dimension');
        }

        $this->image = $this->image->scaleDown($maxW, $maxH);

        return $this;
    }

    /**
     * 矩形裁剪。
     *
     * @param int $x 起点 X（左上）
     * @param int $y 起点 Y
     * @param int $w 裁剪宽
     * @param int $h 裁剪高
     */
    public function crop(int $x, int $y, int $w, int $h): self
    {
        if ($w < 1 || $h < 1) {
            throw new InvalidImageException('Crop width and height must be positive');
        }

        PixelGuard::assertWithinLimit($w, $h, $this->config->maxPixels());
        $this->image = $this->image->crop($w, $h, $x, $y);

        return $this;
    }

    /** @param float $degrees 顺时针角度 */
    public function rotate(float $degrees): self
    {
        $this->image = $this->image->rotate($degrees);

        return $this;
    }

    /** 水平翻转 */
    public function flipHorizontal(): self
    {
        $this->image = $this->image->flip();

        return $this;
    }

    /** 垂直翻转 */
    public function flipVertical(): self
    {
        $this->image = $this->image->flip(Direction::VERTICAL);

        return $this;
    }

    /** 转为灰度 */
    public function greyscale(): self
    {
        $this->image = $this->image->grayscale();

        return $this;
    }

    /**
     * 叠加图片水印（logo），定位与透明度见 {@see ImageWatermark}。
     *
     * logo 先经过 {@see PixelGuard::assertFilePath()} 预检，再交给 Intervention 解码，
     * 避免超大水印图绕过 `max_pixels` 耗尽内存。
     *
     * @throws InvalidImageException 位置非法或 logo 无法解码
     * @throws ImageTooLargeException logo 像素超过 max_pixels
     */
    public function watermarkImage(ImageWatermark $mark): self
    {
        $this->image = $this->guard(function () use ($mark): ImageInterface {
            PixelGuard::assertFilePath($mark->path, $this->config->maxPixels());
            $logo = $this->manager->decodePath($mark->path);
            $anchor = PositionAnchor::insertOffsets($mark->position, $mark->offsetX, $mark->offsetY);

            return $this->image->insert(
                $logo,
                $anchor['x'],
                $anchor['y'],
                $anchor['alignment'],
                max(0.0, min(1.0, $mark->opacity)),
            );
        }, 'Unable to apply image watermark');

        return $this;
    }

    /**
     * 叠加 TTF 文字水印，字体文件须可读。
     *
     * @throws InvalidImageException 位置/颜色/字体非法
     */
    public function watermarkText(TextWatermark $mark): self
    {
        $this->image = $this->guard(function () use ($mark): ImageInterface {
            $anchor = PositionAnchor::resolve(
                $mark->position,
                $this->image->width(),
                $this->image->height(),
                $mark->offsetX,
                $mark->offsetY,
            );
            $color = $this->textColor($mark->color, $mark->opacity);

            return $this->image->text(
                $mark->content,
                $anchor['x'],
                $anchor['y'],
                static function ($font) use ($mark, $anchor, $color): void {
                    $font->filename($mark->font);
                    $font->size($mark->size);
                    $font->color($color);
                    $font->align($anchor['horizontal'], $anchor['vertical']);
                    $font->angle($mark->angle);
                },
            );
        }, 'Unable to apply text watermark');

        return $this;
    }

    /**
     * 应用配置中命名预设 {@see ImageConfig::preset()}。
     *
     * @throws \Swoolefy\Library\Image\Exception\InvalidImageException 预设未配置
     */
    public function applyPreset(string $name): self
    {
        return $this->applyPresetConfig($this->config->preset($name));
    }

    /** 应用 {@see ImagePresetConfig}（可来自配置，也可代码内 new） */
    public function applyPresetConfig(ImagePresetConfig $preset): self
    {
        // 连续 apply 多个 preset 时，无 encode 段的 preset 必须清掉上一次的编码覆盖
        $this->presetEncode = null;

        if ($preset->hasResize()) {
            $this->resize(
                $preset->width > 0 ? $preset->width : null,
                $preset->height > 0 ? $preset->height : null,
                $preset->mode,
            );
        }

        $encode = $preset->encodeOptions();
        if ($encode !== null) {
            $this->presetEncode = $encode;
        }

        return $this;
    }

    /**
     * 编码为二进制并返回元信息。
     *
     * 合并顺序（逐字段覆盖）：全局 `encode` 配置 ← {@see applyPreset()} 附带 ← 本次 $options。
     *
     * 未显式指定 format 时：源图是 png/webp/avif/gif 等可能带透明通道的格式，默认输出 png；
     * 其他来源（含 create() 画布）默认 jpeg，避免透明区域被压成黑/白底。
     *
     * @throws ImageTooLargeException 编码后超过合并后的 encode.max_bytes
     * @throws UnsupportedFormatException 当前 driver 不支持该格式，或 GIF 请求 strip
     * @throws InvalidImageException 编码参数非法（如 quality 越界）
     */
    public function encode(?EncodeOptions $options = null): ProcessResult
    {
        $merged = EncodeOptions::merge($this->config->encodeDefaults(), $this->presetEncode, $options);
        $configured = $merged->format !== null ? trim($merged->format) : '';
        $format = FormatNegotiator::assertSupported(
            $this->config->driver(),
            $configured !== '' ? $configured : $this->defaultEncodeFormat(),
        );
        $quality = $merged->quality ?? 82;
        if ($quality < 0 || $quality > 100) {
            throw new InvalidImageException(sprintf(
                'Image quality must be between 0 and 100, got %d',
                $quality
            ));
        }

        $progressive = $merged->progressive ?? false;
        $strip = $merged->strip;
        if ($format === 'gif' && $strip === true) {
            throw new UnsupportedFormatException('GIF encoder does not support strip; set strip to false or null');
        }

        $encoded = $this->guard(function () use ($format, $quality, $progressive, $strip): EncodedImageInterface {
            $encoder = match ($format) {
                'png' => new PngEncoder(strip: $strip),
                'webp' => new WebpEncoder(quality: $quality, strip: $strip),
                'gif' => new GifEncoder(),
                'avif' => new AvifEncoder(quality: $quality, strip: $strip),
                'jpeg' => new JpegEncoder(quality: $quality, progressive: $progressive, strip: $strip),
                default => throw new UnsupportedFormatException(sprintf('Image format [%s] has no encoder', $format)),
            };

            return $this->image->encode($encoder);
        }, sprintf('Unable to encode image as %s', $format));

        $binary = (string) $encoded;
        $maxBytes = $merged->maxBytes ?? 0;
        if ($maxBytes > 0 && strlen($binary) > $maxBytes) {
            throw new ImageTooLargeException(sprintf('Encoded image exceeds %d bytes', $maxBytes));
        }

        return $this->result($encoded, $format, $binary);
    }

    /** {@see encode()} 的便捷方法，只返回 binary 字符串 */
    public function toBinary(?EncodeOptions $options = null): string
    {
        return $this->encode($options)->binary;
    }

    /** 返回 `data:{mime};base64,...` 形式，可直接用于 img src */
    public function toBase64(?EncodeOptions $options = null): string
    {
        $result = $this->encode($options);

        return 'data:' . $result->mime . ';base64,' . base64_encode($result->binary);
    }

    /** 当前编辑结果的宽高（像素） */
    public function size(): ImageSize
    {
        return new ImageSize($this->image->width(), $this->image->height());
    }

    /**
     * 在画布正中插入已 cover 缩放后的 overlay 图（供 Qrcode Logo 等模块组合使用）。
     *
     * @param string $overlayPath 可读图片路径
     * @param int $targetWidth overlay 目标宽
     * @param int $targetHeight overlay 目标高
     * @param float $opacity 0~1，越大越不透明；1.0 为原图（默认）
     */
    public function overlayCenter(string $overlayPath, int $targetWidth, int $targetHeight, float $opacity = 1.0): self
    {
        $this->assertOverlayTarget($targetWidth, $targetHeight);

        $overlay = $this->guard(function () use ($overlayPath): ImageInterface {
            PixelGuard::assertFilePath($overlayPath, $this->config->maxPixels());

            return $this->manager->decodePath($overlayPath);
        }, 'Unable to decode overlay image');

        return $this->overlayCenterImage($overlay, $targetWidth, $targetHeight, $opacity);
    }

    /**
     * 与 {@see overlayCenter()} 相同，但接收已解码的 overlay，批量场景可复用同一份图像、避免重复 IO。
     *
     * 内部会 clone 后再 cover，不会修改调用方传入的实例。
     *
     * @param ImageInterface $overlay 已解码的叠加图
     * @param int $targetWidth overlay 目标宽
     * @param int $targetHeight overlay 目标高
     * @param float $opacity 0~1，越大越不透明；1.0 为原图（默认）
     */
    public function overlayCenterImage(
        ImageInterface $overlay,
        int $targetWidth,
        int $targetHeight,
        float $opacity = 1.0,
    ): self {
        $this->assertOverlayTarget($targetWidth, $targetHeight);

        $this->image = $this->guard(function () use ($overlay, $targetWidth, $targetHeight, $opacity): ImageInterface {
            // insert() 的 x/y 是相对 CENTER 锚点的偏移，传 0 即精确居中
            return $this->image->insert(
                (clone $overlay)->cover($targetWidth, $targetHeight),
                0,
                0,
                Alignment::CENTER,
                max(0.0, min(1.0, $opacity)),
            );
        }, 'Unable to apply overlay image');

        return $this;
    }

    /**
     * 统一走 {@see encode()}：与普通导出一样应用三级选项合并、`max_bytes` 与异常包装。
     *
     * 供 Qrcode 等内部组合使用，强制 PNG。
     */
    public function encodePngBinary(): string
    {
        return $this->encode(new EncodeOptions(format: 'png'))->binary;
    }

    /**
     * 归一化文字水印颜色：接受 #fff / #ffffff / 命名色，并统一由 alpha 通道生成。
     *
     * v4 的 `#RRGGBBAA` 中 AA 是**不透明度**（00 全透明、ff 不透明），与 `$opacity` 同向，
     * 因此直接传给 `Color::create()`；不透明时 `toHex()` 会自动省略 alpha 返回 6 位。
     *
     * @param float $opacity 0~1，越大越不透明
     *
     * @throws InvalidImageException 颜色无法解析
     */
    private function textColor(string $color, float $opacity): string
    {
        try {
            $rgb = Color::parse($color);
        } catch (\Throwable $e) {
            throw new InvalidImageException(sprintf('Invalid text watermark color "%s"', $color), previous: $e);
        }

        return Color::create(
            $rgb->red()->value(),
            $rgb->green()->value(),
            $rgb->blue()->value(),
            max(0.0, min(1.0, $opacity)),
        )->toHex(prefix: true);
    }

    /**
     * 执行可能抛 Intervention/底层异常的操作，统一包装为模块异常。
     *
     * `ImageException`（含 `ImageTooLargeException`、`UnsupportedFormatException`）原样抛出，
     * 调用方只需 `catch (ImageException)` 即可收口。
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws ImageException
     */
    private function guard(callable $operation, string $context): mixed
    {
        try {
            return $operation();
        } catch (ImageException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidImageException($context . ': ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * 未显式指定输出格式时的默认值：可能含透明通道的源图优先保留为 PNG。
     */
    private function defaultEncodeFormat(): string
    {
        $mediaType = strtolower($this->image->origin()->mediaType());

        return in_array($mediaType, ['image/png', 'image/webp', 'image/avif', 'image/gif'], true)
            ? 'png'
            : 'jpeg';
    }

    /** overlay 目标尺寸校验，路径来源与已解码来源共用 */
    private function assertOverlayTarget(int $width, int $height): void
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidImageException('Overlay target width and height must be positive');
        }

        PixelGuard::assertWithinLimit($width, $height, $this->config->maxPixels());
    }

    /**
     * 双维目标尺寸的 max_pixels 校验；单维时由调用方按原图比例推算。
     */
    private function assertTargetSize(?int $width, ?int $height): void
    {
        if ($width === null || $height === null) {
            return;
        }

        PixelGuard::assertWithinLimit($width, $height, $this->config->maxPixels());
    }

    /**
     * 等比缩放的输出尺寸校验：单维时按原图比例推算另一维，防止小图被放大后超限。
     */
    private function assertScaleTarget(?int $width, ?int $height): void
    {
        if ($width !== null && $height !== null) {
            $this->assertTargetSize($width, $height);

            return;
        }

        $sourceWidth = $this->image->width();
        $sourceHeight = $this->image->height();
        $factor = $width !== null
            ? $width / $sourceWidth
            : $height / $sourceHeight;

        $this->assertTargetSize(
            max(1, (int) round($sourceWidth * $factor)),
            max(1, (int) round($sourceHeight * $factor)),
        );
    }

    /**
     * cover 先按比例放大再裁剪，中间画布可能大于最终尺寸，两者都按 max_pixels 校验。
     */
    private function assertCoverTarget(int $width, int $height): void
    {
        $sourceWidth = $this->image->width();
        $sourceHeight = $this->image->height();
        $factor = max($width / $sourceWidth, $height / $sourceHeight);

        PixelGuard::assertWithinLimit(
            max(1, (int) ceil($sourceWidth * $factor)),
            max(1, (int) ceil($sourceHeight * $factor)),
            $this->config->maxPixels(),
        );
        $this->assertTargetSize($width, $height);
    }

    /**
     * @param EncodedImageInterface $encoded Intervention 编码结果
     * @param string $format 归一化后的格式名（jpeg/png/…）
     * @param string $binary 原始字节
     */
    private function result(EncodedImageInterface $encoded, string $format, string $binary): ProcessResult
    {
        $extension = $format === 'jpeg' ? 'jpg' : $format;
        $mime = $encoded->mediaType() !== 'application/octet-stream'
            ? $encoded->mediaType()
            : match ($format) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                'avif' => 'image/avif',
                default => 'image/jpeg',
            };

        return new ProcessResult(
            $binary,
            $mime,
            $extension,
            strlen($binary),
            $this->size(),
        );
    }
}
