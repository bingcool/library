<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\Support;

use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Qrcode\Exception\InvalidQrcodeContentException;

/**
 * 在已生成的 PNG 二维码正中叠加 Logo（委托 Image 模块，不在此重复绘图）。
 */
final class LogoApplier
{
    public function __construct(private ImageManager $image)
    {
    }

    /**
     * @param string $pngBinary Bacon 输出的 PNG 原始字节
     * @param string $logoPath 可读 Logo 路径
     * @param float $ratio Logo 边长占码图宽度比例
     *
     * @return string 叠加后的 PNG 二进制
     *
     * @throws InvalidQrcodeContentException ratio 或路径非法
     */
    public function apply(string $pngBinary, string $logoPath, float $ratio): string
    {
        if ($ratio <= 0 || $ratio > 0.3) {
            throw new InvalidQrcodeContentException('Logo ratio must be between 0 and 0.3');
        }

        if ($logoPath === '' || !is_file($logoPath)) {
            throw new InvalidQrcodeContentException('Logo file is not readable');
        }

        $editor = $this->image->open($pngBinary);
        $size = $editor->size();
        $side = max(1, (int) round($size->width * $ratio));

        return $editor->overlayCenter($logoPath, $side, $side)->encodePngBinary();
    }
}
