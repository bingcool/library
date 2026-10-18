<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager as InterventionImageManager;
use Psr\Log\LoggerInterface;
use Swoolefy\Library\Image\Config\ImageConfig;
use Swoolefy\Library\Image\Exception\ImageException;
use Swoolefy\Library\Image\Exception\InvalidImageException;
use Swoolefy\Library\Image\Support\ExtensionGuard;
use Swoolefy\Library\Image\Support\PixelGuard;
use Throwable;

/**
 * 图像处理 DI 入口（对齐 Mail {@see \Swoolefy\Library\Mail\MailerManager} 范式）。
 *
 * 职责：
 * - 解析 `Config/image.php`，按 driver 懒加载 Intervention ImageManager
 * - 每次 {@see open()} / {@see create()} 返回独立的 {@see ImageEditor}（请求/协程级，禁止跨协程共享）
 *
 * 协程注意：
 * - 本类只缓存 driver 句柄与 {@see ImageConfig}，**不缓存**任何解码后的位图
 * - 勿在 Worker 进程用 static 长期缓存本 Manager（配置变更后不刷新）
 */
final class ImageManager
{
    /** Intervention 底层 manager，首次 open/create 时按 driver 构建 */
    private ?InterventionImageManager $intervention = null;

    /**
     * @param array<string, mixed>|ImageConfig $config 完整 image 配置或已解析的 ImageConfig
     * @param LoggerInterface|null $logger 可选 PSR-3；预留扩展，当前版本未写日志
     */
    public function __construct(
        private array|ImageConfig $config,
        private ?LoggerInterface $logger = null,
    ) {
        if (is_array($this->config)) {
            $this->config = ImageConfig::fromArray($this->config);
        }
    }

    /**
     * 从多种来源解码图像并返回一次性 Editor。
     *
     * 来源识别顺序：
     * - {@see \SplFileInfo} → decodeSplFileInfo
     * - PHP resource（流）→ decodeStream
     * - 存在且为文件的 string 路径 → decodePath（解码前 {@see PixelGuard::assertFilePath()}）
     * - 其他 string → 按二进制 decodeBinary
     *
     * 成功后会调用 Intervention 的 orient() 按 EXIF 校正方向。
     *
     * @throws InvalidImageException 无法解码或像素超限（{@see ImageTooLargeException} 等 {@see ImageException} 子类会直接抛出，不包装）
     */
    public function open(mixed $source): ImageEditor
    {
        $manager = $this->intervention();

        try {
            if ($source instanceof \SplFileInfo) {
                PixelGuard::assertFilePath($source->getPathname(), $this->config->maxPixels());
                $image = $manager->decodeSplFileInfo($source);
            } elseif (is_resource($source)) {
                $image = $manager->decodeStream($source);
            } elseif (is_string($source) && is_file($source)) {
                PixelGuard::assertFilePath($source, $this->config->maxPixels());
                $image = $manager->decodePath($source);
            } elseif (is_string($source)) {
                $image = $manager->decodeBinary($source);
            } else {
                throw new InvalidImageException('Unsupported image source type');
            }
        } catch (ImageException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidImageException('Unable to decode image: ' . $e->getMessage(), 0, $e);
        }

        PixelGuard::assertWithinLimit($image->width(), $image->height(), $this->config->maxPixels());
        $image->orient();

        return new ImageEditor($image, $this->config, $manager);
    }

    /**
     * 创建纯色空白画布。
     *
     * @param int $width 像素宽，须 ≥ 1
     * @param int $height 像素高，须 ≥ 1
     * @param string $background 填充色，Intervention 可识别的 hex / 色名
     *
     * @throws InvalidImageException 尺寸非法或超过 max_pixels
     */
    public function create(int $width, int $height, string $background = '#ffffff'): ImageEditor
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidImageException('Canvas width and height must be positive');
        }

        PixelGuard::assertWithinLimit($width, $height, $this->config->maxPixels());
        $manager = $this->intervention();
        $image = $manager->createImage($width, $height)->fill($background);

        return new ImageEditor($image, $this->config, $manager);
    }

    /** 返回只读配置访问器，供业务读取 driver / preset 等 */
    public function config(): ImageConfig
    {
        return $this->config;
    }

    /**
     * 懒加载 Intervention ImageManager，并检测 gd/imagick 扩展。
     *
     * @throws \Swoolefy\Library\Image\Exception\MissingExtensionException driver 对应扩展未安装
     */
    private function intervention(): InterventionImageManager
    {
        if ($this->intervention instanceof InterventionImageManager) {
            return $this->intervention;
        }

        ExtensionGuard::assertDriver($this->config->driver());
        $driver = $this->config->driver() === 'imagick'
            ? new ImagickDriver()
            : new GdDriver();
        $this->intervention = new InterventionImageManager($driver);

        return $this->intervention;
    }
}
