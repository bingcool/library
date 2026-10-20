<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Watermark;

use Swoolefy\Library\Image\Config\ImageConfig;
use Swoolefy\Library\Image\Config\ImageWatermarkConfig;
use Swoolefy\Library\Image\Support\PathResolver;

/**
 * 图片 logo 水印参数。路径在构造时解析为 realpath，拒绝 `..` 穿越，并可按白名单限制根目录。
 */
final class ImageWatermark
{
    /** 已通过 {@see PathResolver} 校验的可读绝对路径 */
    public readonly string $path;

    /**
     * @param string $path logo 文件路径
     * @param string $position 九宫格：bottom-right、center 等，见 Intervention Alignment
     * @param int $offsetX 相对锚点的水平边距（像素）
     * @param int $offsetY 相对锚点的垂直边距（像素）
     * @param float $opacity 0~1，越大水印越不透明；直接作为 v4 insert 的不透明度因子（1.0 为原图）
     * @param list<string> $allowedRoots 允许读取 logo 的根目录；空数组表示不额外限制
     * @param string|null $basePath 相对路径基准；null 时用 `APP_PATH` / CWD
     */
    public function __construct(
        string $path,
        public readonly string $position = 'bottom-right',
        public readonly int $offsetX = 16,
        public readonly int $offsetY = 16,
        public readonly float $opacity = 0.6,
        public readonly array $allowedRoots = [],
        public readonly ?string $basePath = null,
    ) {
        $this->path = PathResolver::resolve($path, $basePath);
        PathResolver::assertWithinRoots($this->path, $allowedRoots, $basePath);
    }

    /** 从 {@see ImageWatermarkConfig} 构建运行时水印（校验 path 与允许根目录） */
    public static function fromWatermarkConfig(ImageWatermarkConfig $config): self
    {
        return new self(
            $config->path,
            $config->position,
            $config->offsetX,
            $config->offsetY,
            $config->opacity,
            $config->allowedRoots,
            $config->basePath,
        );
    }

    /**
     * 从整包 Image 配置读取 watermark.image，$overrides 覆盖同名字段。
     *
     * @param array<string, mixed> $overrides 键名与 php 配置一致（offset_x 等）
     */
    public static function fromConfig(ImageConfig $config, array $overrides = []): self
    {
        return self::fromWatermarkConfig($config->watermarkImage()->withOverrides($overrides));
    }
}
