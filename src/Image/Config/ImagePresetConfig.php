<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Config;

use Swoolefy\Library\Image\ValueObject\EncodeOptions;

/**
 * `Config/image.php` 中 `presets.{name}` 单条预设的只读视图。
 *
 * 供 {@see \Swoolefy\Library\Image\ImageEditor::applyPreset()} / {@see applyPresetConfig()} 使用。
 */
final class ImagePresetConfig
{
    /**
     * @param string $mode contain|cover|scale|stretch|fill，见 {@see \Swoolefy\Library\Image\ImageEditor::resize()}
     * @param int $width 目标宽；0 表示该维不限制（contain 时常见）
     * @param int $height 目标高；0 表示该维不限制
     * @param array<string, mixed>|null $encode 非 null 时在 encode() 阶段覆盖全局 encode 默认
     */
    public function __construct(
        public readonly string $mode = 'contain',
        public readonly int $width = 0,
        public readonly int $height = 0,
        public readonly ?array $encode = null,
    ) {
    }

    /**
     * @param array<string, mixed> $config 单条 preset 数组（mode、size、encode）
     */
    public static function fromArray(array $config): self
    {
        [$w, $h] = array_pad((array) ($config['size'] ?? []), 2, 0);

        $encode = $config['encode'] ?? null;

        return new self(
            mode: (string) ($config['mode'] ?? 'contain'),
            width: (int) $w,
            height: (int) $h,
            encode: is_array($encode) ? $encode : null,
        );
    }

    /** 是否应执行 resize（宽或高至少一侧 &gt; 0） */
    public function hasResize(): bool
    {
        return $this->width > 0 || $this->height > 0;
    }

    /** preset 附带的编码覆盖；无 encode 段时返回 null */
    public function encodeOptions(): ?EncodeOptions
    {
        if ($this->encode === null) {
            return null;
        }

        return EncodeOptions::merge($this->encode, null);
    }
}
