<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Support;

/**
 * 纯函数：根据原图与目标框计算缩放后尺寸（不调用 GD/Imagick，便于单测）。
 *
 * {@see \Swoolefy\Library\Image\ImageEditor::resize()} 的 contain 分支直接复用
 * {@see contain()}，保证测试与生产使用同一套「只缩不放」语义。
 */
final class SizeCalculator
{
    /**
     * 等比缩进目标框内（只缩小，不放大超过原图逻辑由调用方决定）。
     *
     * @param int $maxW 最大宽；0 表示该维不限制
     * @param int $maxH 最大高；0 表示该维不限制
     *
     * @return array{width: int, height: int}
     */
    public static function contain(int $sourceW, int $sourceH, int $maxW, int $maxH): array
    {
        if ($sourceW < 1 || $sourceH < 1) {
            return ['width' => max(0, $maxW), 'height' => max(0, $maxH)];
        }

        $limitW = $maxW > 0 ? $maxW : $sourceW;
        $limitH = $maxH > 0 ? $maxH : $sourceH;
        $scale = min($limitW / $sourceW, $limitH / $sourceH, 1.0);

        return [
            'width' => max(1, (int) round($sourceW * $scale)),
            'height' => max(1, (int) round($sourceH * $scale)),
        ];
    }

    /**
     * 等比放大以覆盖目标框（cover 前的中间尺寸，实际裁剪由 Intervention cover() 完成）。
     *
     * @return array{width: int, height: int}
     */
    public static function cover(int $sourceW, int $sourceH, int $targetW, int $targetH): array
    {
        if ($targetW < 1 || $targetH < 1 || $sourceW < 1 || $sourceH < 1) {
            return ['width' => max(1, $targetW), 'height' => max(1, $targetH)];
        }

        $scale = max($targetW / $sourceW, $targetH / $sourceH);

        return [
            'width' => max(1, (int) round($sourceW * $scale)),
            'height' => max(1, (int) round($sourceH * $scale)),
        ];
    }
}
