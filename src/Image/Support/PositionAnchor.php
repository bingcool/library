<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Support;

use Intervention\Image\Alignment;

/**
 * 将配置里的九宫格位置（bottom-right 等）解析为 insert/text 所需的参数。
 *
 * - {@see resolve()}：返回**绝对坐标**，供 `text()` 使用（text 的 position 本身就是锚点）。
 * - {@see insertOffsets()}：返回**相对锚点的偏移量**，供 `insert()` 使用（v4 语义）。
 *
 * 两者不能混用：把绝对坐标传给 `insert()` 会被再次按锚点扣减，导致九宫格错位。
 */
final class PositionAnchor
{
    /**
     * `insert()` 所需参数。
     *
     * v4 `insert($image, $x, $y, $alignment)` 的 `$x/$y` 是**相对 $alignment 锚点的偏移量**：
     * `Size::movePivot()` 对右侧/底部执行 `width - x`、`height - y`，因此正数表示向内缩进。
     * 水平/垂直居中的位置会忽略对应轴的 offset，与 `text()` 端 {@see resolve()} 保持一致。
     *
     * @param int $offsetX 距左/右边距（仅 left/right 系生效）
     * @param int $offsetY 距上/下边距（仅 top/bottom 系生效）
     *
     * @return array{x: int, y: int, alignment: Alignment}
     */
    public static function insertOffsets(string $position, int $offsetX, int $offsetY): array
    {
        $alignment = Alignment::create($position);
        $horizontalCentered = in_array($alignment, [Alignment::TOP, Alignment::CENTER, Alignment::BOTTOM], true);
        $verticalCentered = in_array($alignment, [Alignment::LEFT, Alignment::CENTER, Alignment::RIGHT], true);

        return [
            'x' => $horizontalCentered ? 0 : $offsetX,
            'y' => $verticalCentered ? 0 : $offsetY,
            'alignment' => $alignment,
        ];
    }

    /**
     * `text()` 所需参数。
     *
     * @return array{
     *     x: int,
     *     y: int,
     *     alignment: Alignment,
     *     horizontal: Alignment,
     *     vertical: Alignment
     * }
     */
    public static function resolve(string $position, int $width, int $height, int $offsetX, int $offsetY): array
    {
        $alignment = Alignment::create($position);

        return match ($alignment) {
            Alignment::TOP_LEFT => [
                'x' => $offsetX,
                'y' => $offsetY,
                'alignment' => $alignment,
                'horizontal' => Alignment::LEFT,
                'vertical' => Alignment::TOP,
            ],
            Alignment::TOP => [
                'x' => (int) ($width / 2),
                'y' => $offsetY,
                'alignment' => $alignment,
                'horizontal' => Alignment::CENTER,
                'vertical' => Alignment::TOP,
            ],
            Alignment::TOP_RIGHT => [
                'x' => max(0, $width - $offsetX),
                'y' => $offsetY,
                'alignment' => $alignment,
                'horizontal' => Alignment::RIGHT,
                'vertical' => Alignment::TOP,
            ],
            Alignment::LEFT => [
                'x' => $offsetX,
                'y' => (int) ($height / 2),
                'alignment' => $alignment,
                'horizontal' => Alignment::LEFT,
                'vertical' => Alignment::CENTER,
            ],
            Alignment::CENTER => [
                'x' => (int) ($width / 2),
                'y' => (int) ($height / 2),
                'alignment' => $alignment,
                'horizontal' => Alignment::CENTER,
                'vertical' => Alignment::CENTER,
            ],
            Alignment::RIGHT => [
                'x' => max(0, $width - $offsetX),
                'y' => (int) ($height / 2),
                'alignment' => $alignment,
                'horizontal' => Alignment::RIGHT,
                'vertical' => Alignment::CENTER,
            ],
            Alignment::BOTTOM_LEFT => [
                'x' => $offsetX,
                'y' => max(0, $height - $offsetY),
                'alignment' => $alignment,
                'horizontal' => Alignment::LEFT,
                'vertical' => Alignment::BOTTOM,
            ],
            Alignment::BOTTOM => [
                'x' => (int) ($width / 2),
                'y' => max(0, $height - $offsetY),
                'alignment' => $alignment,
                'horizontal' => Alignment::CENTER,
                'vertical' => Alignment::BOTTOM,
            ],
            default => [
                'x' => max(0, $width - $offsetX),
                'y' => max(0, $height - $offsetY),
                'alignment' => Alignment::BOTTOM_RIGHT,
                'horizontal' => Alignment::RIGHT,
                'vertical' => Alignment::BOTTOM,
            ],
        };
    }
}
