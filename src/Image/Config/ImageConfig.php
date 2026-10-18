<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Config;

use Swoolefy\Library\Image\Exception\InvalidImageException;

/**
 * `Config/image.php` 只读访问器。{@see fromArray()} 兼容外层再包一层 `image` 键。
 */
final class ImageConfig
{
    /**
     * @param array<string, mixed> $config 已剥离外层 `image` 键的配置体
     */
    public function __construct(private array $config)
    {
    }

    /**
     * @param array<string, mixed> $config 完整配置或仅 image 段
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['image']) && is_array($config['image'])) {
            $config = $config['image'];
        }

        return new self($config);
    }

    /** gd | imagick，非法值回退 gd */
    public function driver(): string
    {
        $driver = strtolower(trim((string) ($this->config['driver'] ?? 'gd')));

        return in_array($driver, ['gd', 'imagick'], true) ? $driver : 'gd';
    }

    /** 单张图允许的最大像素数（宽×高），0 表示不限制 */
    public function maxPixels(): int
    {
        return max(0, (int) ($this->config['max_pixels'] ?? 40_000_000));
    }

    /**
     * 默认编码参数：format、quality、progressive、strip、max_bytes 等。
     *
     * @return array<string, mixed>
     */
    public function encodeDefaults(): array
    {
        $encode = $this->config['encode'] ?? [];

        return is_array($encode) ? $encode : [];
    }

    /** `watermark.image` 段 typed 访问 */
    public function watermarkImage(): ImageWatermarkConfig
    {
        $watermark = $this->config['watermark']['image'] ?? [];

        return ImageWatermarkConfig::fromArray(is_array($watermark) ? $watermark : []);
    }

    /** `watermark.text` 段 typed 访问 */
    public function watermarkText(): TextWatermarkConfig
    {
        $watermark = $this->config['watermark']['text'] ?? [];

        return TextWatermarkConfig::fromArray(is_array($watermark) ? $watermark : []);
    }

    /** 整个 `presets` 段 */
    public function presets(): ImagePresetsConfig
    {
        $presets = $this->config['presets'] ?? [];

        return ImagePresetsConfig::fromArray(is_array($presets) ? $presets : []);
    }

    /**
     * 按名取单条预设。
     *
     * @throws InvalidImageException 预设名不存在
     */
    public function preset(string $name): ImagePresetConfig
    {
        return $this->presets()->get($name);
    }
}
