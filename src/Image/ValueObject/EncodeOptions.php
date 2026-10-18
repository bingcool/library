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
    ) {
    }

    /**
     * 将配置数组与运行时 EncodeOptions 合并为完整选项（运行时非 null 字段优先）。
     *
     * @param array<string, mixed> $defaults 来自 ImageConfig::encodeDefaults()
     */
    public static function merge(array $defaults, ?self $override): self
    {
        if ($override === null) {
            return new self(
                isset($defaults['format']) ? (string) $defaults['format'] : null,
                isset($defaults['quality']) ? (int) $defaults['quality'] : null,
                isset($defaults['progressive']) ? (bool) $defaults['progressive'] : null,
                isset($defaults['strip']) ? (bool) $defaults['strip'] : null,
            );
        }

        return new self(
            $override->format ?? (isset($defaults['format']) ? (string) $defaults['format'] : null),
            $override->quality ?? (isset($defaults['quality']) ? (int) $defaults['quality'] : null),
            $override->progressive ?? (isset($defaults['progressive']) ? (bool) $defaults['progressive'] : null),
            $override->strip ?? (isset($defaults['strip']) ? (bool) $defaults['strip'] : null),
        );
    }
}
