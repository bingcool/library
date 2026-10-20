<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\ValueObject;

/**
 * 单次编码选项。null 字段表示「使用配置默认，不覆盖」。
 */
final class EncodeOptions
{
    public function __construct(
        /** jpeg|png|webp|gif|avif 等；null 用配置默认 */
        public readonly ?string $format = null,
        /** 有损格式质量 0–100；null 用配置默认 */
        public readonly ?int $quality = null,
        /** JPEG 是否渐进式；null 用配置默认 */
        public readonly ?bool $progressive = null,
        /** 编码时是否 strip EXIF；null 用 encoder 默认 */
        public readonly ?bool $strip = null,
        /** 编码后允许的最大字节数；null 用配置默认，0 表示不限制 */
        public readonly ?int $maxBytes = null,
    ) {
    }

    /**
     * 将配置数组与任意层级的 EncodeOptions 按顺序逐字段合并（后者非 null 字段覆盖前者）。
     *
     * 典型调用：`merge($globalEncode, $presetEncode, $runtimeOptions)`，实现
     * 「全局配置 ← preset ← 本次调用」三级优先级，而不是整体替换。
     *
     * @param array<string, mixed> $defaults 来自 ImageConfig::encodeDefaults()
     * @param self|null ...$overrides 由低到高的覆盖层，null 层自动跳过
     */
    public static function merge(array $defaults, ?self ...$overrides): self
    {
        $merged = new self(
            isset($defaults['format']) ? (string) $defaults['format'] : null,
            isset($defaults['quality']) ? (int) $defaults['quality'] : null,
            isset($defaults['progressive']) ? (bool) $defaults['progressive'] : null,
            isset($defaults['strip']) ? (bool) $defaults['strip'] : null,
            isset($defaults['max_bytes']) ? max(0, (int) $defaults['max_bytes']) : null,
        );

        foreach ($overrides as $override) {
            if (!$override instanceof self) {
                continue;
            }

            $merged = new self(
                $override->format ?? $merged->format,
                $override->quality ?? $merged->quality,
                $override->progressive ?? $merged->progressive,
                $override->strip ?? $merged->strip,
                $override->maxBytes ?? $merged->maxBytes,
            );
        }

        return $merged;
    }
}
