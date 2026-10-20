<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Config;

/**
 * `Config/image.php` 中 `watermark.text` 段的只读视图。
 *
 * 供 {@see \Swoolefy\Library\Image\Watermark\TextWatermark::fromWatermarkConfig()} 使用。
 */
final class TextWatermarkConfig
{
    public function __construct(
        public readonly string $content = '',
        /** TTF/OTF 路径，须可读 */
        public readonly string $font = '',
        public readonly float $size = 28,
        public readonly string $color = '#ffffff',
        public readonly float $opacity = 0.5,
        public readonly string $position = 'bottom-right',
        public readonly int $offsetX = 16,
        public readonly int $offsetY = 16,
        public readonly float $angle = 0,
        /** 相对字体路径基准目录；null 时由 ImageConfig 注入 */
        public readonly ?string $basePath = null,
    ) {
    }

    /**
     * @param array<string, mixed> $config watermark.text 或等价数组
     */
    public static function fromArray(array $config): self
    {
        return new self(
            content: (string) ($config['content'] ?? ''),
            font: (string) ($config['font'] ?? ''),
            size: (float) ($config['size'] ?? 28),
            color: (string) ($config['color'] ?? '#ffffff'),
            opacity: (float) ($config['opacity'] ?? 0.5),
            position: (string) ($config['position'] ?? 'bottom-right'),
            offsetX: (int) ($config['offset_x'] ?? 16),
            offsetY: (int) ($config['offset_y'] ?? 16),
            angle: (float) ($config['angle'] ?? 0),
            basePath: is_string($config['base_path'] ?? null) && trim((string) $config['base_path']) !== ''
                ? trim((string) $config['base_path'])
                : null,
        );
    }

    /**
     * @param array<string, mixed> $overrides 键名与配置文件一致
     */
    public function withOverrides(array $overrides): self
    {
        if ($overrides === []) {
            return $this;
        }

        return new self(
            content: array_key_exists('content', $overrides) ? (string) $overrides['content'] : $this->content,
            font: array_key_exists('font', $overrides) ? (string) $overrides['font'] : $this->font,
            size: array_key_exists('size', $overrides) ? (float) $overrides['size'] : $this->size,
            color: array_key_exists('color', $overrides) ? (string) $overrides['color'] : $this->color,
            opacity: array_key_exists('opacity', $overrides) ? (float) $overrides['opacity'] : $this->opacity,
            position: array_key_exists('position', $overrides) ? (string) $overrides['position'] : $this->position,
            offsetX: array_key_exists('offset_x', $overrides) ? (int) $overrides['offset_x'] : $this->offsetX,
            offsetY: array_key_exists('offset_y', $overrides) ? (int) $overrides['offset_y'] : $this->offsetY,
            angle: array_key_exists('angle', $overrides) ? (float) $overrides['angle'] : $this->angle,
            basePath: array_key_exists('base_path', $overrides) && is_string($overrides['base_path'])
                ? (trim($overrides['base_path']) !== '' ? trim($overrides['base_path']) : null)
                : $this->basePath,
        );
    }
}
