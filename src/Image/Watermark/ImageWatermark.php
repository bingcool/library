<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Watermark;

use Swoolefy\Library\Image\Config\ImageConfig;
use Swoolefy\Library\Image\Config\ImageWatermarkConfig;
use Swoolefy\Library\Image\Exception\InvalidImageException;

/**
 * 图片 logo 水印参数。路径在构造时 resolve 为 realpath，拒绝 `..` 穿越。
 */
final class ImageWatermark
{
    /** 已通过 {@see resolvePath()} 校验的可读绝对路径 */
    public readonly string $path;

    /**
     * @param string $path logo 文件路径
     * @param string $position 九宫格：bottom-right、center 等，见 Intervention Alignment
     * @param int $offsetX 相对锚点的水平边距（像素）
     * @param int $offsetY 相对锚点的垂直边距（像素）
     * @param float $opacity 0~1，越大水印越不透明（内部转换为 Intervention insert 透明度）
     */
    public function __construct(
        string $path,
        public readonly string $position = 'bottom-right',
        public readonly int $offsetX = 16,
        public readonly int $offsetY = 16,
        public readonly float $opacity = 0.6,
    ) {
        $this->path = self::resolvePath($path);
    }

    /** 从 {@see ImageWatermarkConfig} 构建运行时水印（校验 path） */
    public static function fromWatermarkConfig(ImageWatermarkConfig $config): self
    {
        return new self(
            $config->path,
            $config->position,
            $config->offsetX,
            $config->offsetY,
            $config->opacity,
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

    /**
     * @throws InvalidImageException 路径非法或不可读
     */
    private static function resolvePath(string $path): string
    {
        if ($path === '' || preg_match('#(?:^|[/\\\\])\.\.(?:$|[/\\\\])#', $path) === 1) {
            throw new InvalidImageException('Watermark image path is not allowed');
        }

        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new InvalidImageException('Watermark image is not a readable file');
        }

        return $real;
    }
}
