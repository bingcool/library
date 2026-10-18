<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\ValueObject;

/** 图像宽高（像素），不含 EXIF 方向以外的元数据 */
final class ImageSize
{
    public function __construct(
        /** 宽度（像素） */
        public readonly int $width,
        /** 高度（像素） */
        public readonly int $height,
    ) {
    }
}
