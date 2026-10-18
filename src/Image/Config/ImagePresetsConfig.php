<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Config;

use Swoolefy\Library\Image\Exception\InvalidImageException;

/**
 * `Config/image.php` 中整个 `presets` 段的只读视图。
 */
final class ImagePresetsConfig
{
    /**
     * @param array<string, ImagePresetConfig> $presets 键名为 thumb、list 等
     */
    public function __construct(private array $presets)
    {
    }

    /**
     * @param array<string, mixed> $config presets 段（name => preset array）
     */
    public static function fromArray(array $config): self
    {
        $presets = [];
        foreach ($config as $name => $item) {
            if (!is_string($name) || !is_array($item)) {
                continue;
            }
            $presets[$name] = ImagePresetConfig::fromArray($item);
        }

        return new self($presets);
    }

    public function has(string $name): bool
    {
        return isset($this->presets[$name]);
    }

    /**
     * @throws InvalidImageException 预设名不存在
     */
    public function get(string $name): ImagePresetConfig
    {
        if (!isset($this->presets[$name])) {
            throw new InvalidImageException(sprintf('Image preset [%s] is not configured', $name));
        }

        return $this->presets[$name];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->presets);
    }
}
