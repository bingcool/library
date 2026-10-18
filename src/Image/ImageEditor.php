<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image;

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
use Swoolefy\Library\Image\Exception\ImageTooLargeException;
use Swoolefy\Library\Image\Support\FormatNegotiator;
use Swoolefy\Library\Image\Support\PositionAnchor;
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
     * @param int|null $w 目标宽；contain 模式下 0 或 null 表示该维不限制
     * @param int|null $h 目标高；contain 模式下 0 或 null 表示该维不限制
     * @param string $mode contain|cover|scale|stretch|fill
     *                     - contain：等比缩进框内（宽或高一侧为 0 时仅 scaleDown 该维）
     *                     - cover：等比铺满后居中裁剪
     *                     - scale/stretch/fill：强制 resize 到指定宽高
     */
    public function resize(?int $w, ?int $h, string $mode = 'contain'): self
    {
        $mode = strtolower($mode);
        if ($mode === 'cover') {
            $this->image = $this->image->cover(max(1, $w ?? 1), max(1, $h ?? 1));

            return $this;
        }
        if (in_array($mode, ['scale', 'stretch', 'fill'], true)) {
            $this->image = $this->image->resize($w, $h);

            return $this;
        }

        if ($w !== null && $w > 0 && ($h === null || $h < 1)) {
            $this->image = $this->image->scaleDown(width: $w);

            return $this;
        }
        if ($h !== null && $h > 0 && ($w === null || $w < 1)) {
            $this->image = $this->image->scaleDown(height: $h);

            return $this;
        }

        $this->image = $this->image->contain(max(1, $w ?? 1), max(1, $h ?? 1));

        return $this;
    }

    /** 只缩小不放大：宽高均不超过给定上限 */
    public function thumbnail(int $maxW, int $maxH): self
    {
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

    /** 叠加图片水印（logo），透明度与九宫格定位见 {@see ImageWatermark} */
    public function watermarkImage(ImageWatermark $mark): self
    {
        $anchor = PositionAnchor::resolve(
            $mark->position,
            $this->image->width(),
            $this->image->height(),
            $mark->offsetX,
            $mark->offsetY,
        );
        $opacity = max(0.0, min(1.0, 1.0 - $mark->opacity));
        $this->image = $this->image->insert(
            $mark->path,
            $anchor['x'],
            $anchor['y'],
            $anchor['alignment'],
            $opacity,
        );

        return $this;
    }

    /** 叠加 TTF 文字水印，字体文件须可读 */
    public function watermarkText(TextWatermark $mark): self
    {
        $anchor = PositionAnchor::resolve(
            $mark->position,
            $this->image->width(),
            $this->image->height(),
            $mark->offsetX,
            $mark->offsetY,
        );
        $alpha = (int) round((1.0 - max(0.0, min(1.0, $mark->opacity))) * 100);
        $color = $mark->color . sprintf('%02x', max(0, min(100, 100 - $alpha)));

        $this->image = $this->image->text(
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
     * 合并顺序：全局 `encode` 配置 ← {@see applyPreset()} 附带 ← 本次 $options。
     *
     * @throws ImageTooLargeException 编码后超过 encode.max_bytes
     * @throws \Swoolefy\Library\Image\Exception\UnsupportedFormatException 当前 driver 不支持该格式
     */
    public function encode(?EncodeOptions $options = null): ProcessResult
    {
        $merged = EncodeOptions::merge($this->config->encodeDefaults(), $options ?? $this->presetEncode);
        $format = FormatNegotiator::assertSupported($this->config->driver(), (string) ($merged->format ?? 'jpeg'));
        $quality = $merged->quality ?? 82;
        $progressive = $merged->progressive ?? false;
        $strip = $merged->strip;

        $encoder = match ($format) {
            'png' => new PngEncoder(strip: $strip),
            'webp' => new WebpEncoder(quality: $quality, strip: $strip),
            'gif' => new GifEncoder(),
            'avif' => new AvifEncoder(quality: $quality, strip: $strip),
            default => new JpegEncoder(quality: $quality, progressive: $progressive, strip: $strip),
        };

        $encoded = $this->image->encode($encoder);
        $binary = (string) $encoded;
        $maxBytes = (int) ($this->config->encodeDefaults()['max_bytes'] ?? 0);
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
     * @param float $opacity Intervention insert 透明度参数，0~1
     */
    public function overlayCenter(string $overlayPath, int $targetWidth, int $targetHeight, float $opacity = 0): self
    {
        $overlay = $this->manager->decodePath($overlayPath)->cover($targetWidth, $targetHeight);
        $x = (int) max(0, ($this->image->width() - $targetWidth) / 2);
        $y = (int) max(0, ($this->image->height() - $targetHeight) / 2);
        $this->image = $this->image->insert($overlay, $x, $y, \Intervention\Image\Alignment::CENTER, max(0.0, min(1.0, $opacity)));

        return $this;
    }

    /** 不经过 EncodeOptions 合并，直接 PNG 编码（内部组合用） */
    public function encodePngBinary(): string
    {
        return (string) $this->image->encode(new PngEncoder());
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
