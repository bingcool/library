<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Support;

use Intervention\Image\Alignment;

/**
 * 将配置里的九宫格位置（bottom-right 等）解析为 insert/text 所需的坐标与 Alignment。
 */
final class PositionAnchor
{
    /**
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
