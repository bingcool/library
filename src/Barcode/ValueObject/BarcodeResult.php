<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode\ValueObject;

/** 条形码生成成功结果（仅 encode，不含扫码解码） */
final class BarcodeResult
{
    public function __construct(
        /** SVG XML 或 PNG 原始字节 */
        public readonly string $binary,
        /** 如 image/svg+xml、image/png */
        public readonly string $mime,
        /** svg|png */
        public readonly string $format,
        /** 实际编码的载荷（可能与 Builder 初始 content 相同） */
        public readonly string $content,
    ) {
    }
}
