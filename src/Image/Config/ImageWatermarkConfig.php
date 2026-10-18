<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Config;

/**
 * `Config/image.php` 中 `watermark.image` 段的只读视图。
 *
 * 描述默认 logo 路径与定位，供 {@see \Swoolefy\Library\Image\Watermark\ImageWatermark::fromWatermarkConfig()} 使用。
 */
final class ImageWatermarkConfig
{
    public function __construct(
        /** logo 文件路径（相对 APP_PATH 或绝对路径） */
        public readonly string $path = '',
        /** 九宫格锚点：bottom-right、center 等 */
        public readonly string $position = 'bottom-right',
        public readonly int $offsetX = 16,
        public readonly int $offsetY = 16,
        /** 0~1，越大水印越不透明 */
        public readonly float $opacity = 0.6,
    ) {
    }

    /**
     * @param array<string, mixed> $config watermark.image 或等价数组
     */
    public static function fromArray(array $config): self
    {
        return new self(
            path: (string) ($config['path'] ?? ''),
            position: (string) ($config['position'] ?? 'bottom-right'),
            offsetX: (int) ($config['offset_x'] ?? 16),
            offsetY: (int) ($config['offset_y'] ?? 16),
            opacity: (float) ($config['opacity'] ?? 0.6),
        );
    }

    /**
     * 运行时覆盖部分字段（键名与配置文件一致：offset_x、offset_y 等）。
     *
     * @param array<string, mixed> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        if ($overrides === []) {
            return $this;
        }

        return new self(
            path: array_key_exists('path', $overrides) ? (string) $overrides['path'] : $this->path,
            position: array_key_exists('position', $overrides) ? (string) $overrides['position'] : $this->position,
            offsetX: array_key_exists('offset_x', $overrides) ? (int) $overrides['offset_x'] : $this->offsetX,
            offsetY: array_key_exists('offset_y', $overrides) ? (int) $overrides['offset_y'] : $this->offsetY,
            opacity: array_key_exists('opacity', $overrides) ? (float) $overrides['opacity'] : $this->opacity,
        );
    }
}
