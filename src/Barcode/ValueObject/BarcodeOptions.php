<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode\ValueObject;

use Swoolefy\Library\Barcode\BarcodeType;

/**
 * 单次条形码渲染参数（{@see \Swoolefy\Library\Barcode\BarcodeBuilder} 内部可变状态）。
 */
final class BarcodeOptions
{
    public function __construct(
        /** 条码载荷字符串（各制式字符集/长度由 TypeMapper 校验） */
        public string $content = '',
        /** picqer 制式枚举 */
        public BarcodeType $type = BarcodeType::CODE_128,
        /** 条图渲染高度（像素），映射 Renderer 的 height 参数 */
        public int $height = 150,
        /**
         * 输出总宽度（像素）。0 表示按 {@see \Picqer\Barcode\Barcode::getWidth()} ×2 与下限 200 自动计算。
         * 与 picqer v2 的 widthFactor 语义不同，见 picqer README。
         */
        public int $width = 0,
        /**
         * 是否在条下方绘制人类可读文案。
         * SVG 由 BarcodeRenderer 自绘 <text> 生效；PNG 受 picqer v3 限制不绘制文字，此标志无效。
         */
        public bool $showText = true,
    ) {
    }
}
