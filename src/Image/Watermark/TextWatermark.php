<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Watermark;

use Swoolefy\Library\Image\Config\ImageConfig;
use Swoolefy\Library\Image\Config\TextWatermarkConfig;
use Swoolefy\Library\Image\Exception\InvalidImageException;

/** TTF 文字水印参数。字体文件必须在构造时可读。 */
final class TextWatermark
{
    /**
     * @param string $content 水印文字，不可为空
     * @param string $font TTF/OTF 字体文件绝对或相对路径（须可读）
     * @param float $size 字号（Intervention 字体单位）
     * @param string $color 前景色 hex，如 #ffffff
     * @param float $opacity 0~1，越大越不透明
     * @param string $position 九宫格位置
     * @param int $offsetX 边距 X
     * @param int $offsetY 边距 Y
     * @param float $angle 旋转角度（度）
     */
    public function __construct(
        public readonly string $content,
        public readonly string $font,
        public readonly float $size = 28,
        public readonly string $color = '#ffffff',
        public readonly float $opacity = 0.5,
        public readonly string $position = 'bottom-right',
        public readonly int $offsetX = 16,
        public readonly int $offsetY = 16,
        public readonly float $angle = 0,
    ) {
        if ($content === '') {
            throw new InvalidImageException('Text watermark content is required');
        }

        if ($font === '' || !is_file($font) || !is_readable($font)) {
            throw new InvalidImageException('Text watermark font is not readable');
        }
    }

    /** 从 {@see TextWatermarkConfig} 构建运行时水印（校验 content、font） */
    public static function fromWatermarkConfig(TextWatermarkConfig $config): self
    {
        return new self(
            $config->content,
            $config->font,
            $config->size,
            $config->color,
            $config->opacity,
            $config->position,
            $config->offsetX,
            $config->offsetY,
            $config->angle,
        );
    }

    /**
     * @param array<string, mixed> $overrides 键名与 php 配置一致
     */
    public static function fromConfig(ImageConfig $config, array $overrides = []): self
    {
        return self::fromWatermarkConfig($config->watermarkText()->withOverrides($overrides));
    }
}
