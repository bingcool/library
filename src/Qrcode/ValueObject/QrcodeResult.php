<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\ValueObject;

/** 二维码生成成功结果（仅 encode，不含解码能力） */
final class QrcodeResult
{
    public function __construct(
        /** PNG/SVG/EPS 原始字节或 XML 字符串 */
        public readonly string $binary,
        /** 如 image/png、image/svg+xml */
        public readonly string $mime,
        /** png|svg|eps */
        public readonly string $format,
        /** 实际写入二维码的正文（trim 后） */
        public readonly string $content,
    ) {
    }
}
