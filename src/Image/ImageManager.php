<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager as InterventionImageManager;
use Intervention\Image\Interfaces\ImageInterface;
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

        // 配置值（driver 拼写等）在构造期校验；扩展是否安装仍延迟到首次 open/create
        $this->config->driver();
    }

    /**
     * 从多种来源解码图像并返回一次性 Editor。
     *
     * 来源识别顺序：
     * - {@see \SplFileInfo} / 存在且为文件的 string 路径 → 基于文件句柄预检并解码（见 {@see decodeFilePath()}）
     * - PHP resource（流）→ 先 rewind 再读头部预检，随后交给 decodeStream
     * - 其他 string → 按二进制 decodeBinary（解码前 {@see PixelGuard::assertBinary()}）
     *
     * 成功后都会再复核一次像素上限（兜底）。EXIF 方向由 Intervention 解码器的
     * `autoOrientation`（默认 true）统一处理一次，这里**不再**重复 `orient()`。
     *
     * @throws InvalidImageException 无法解码或像素超限（{@see ImageTooLargeException} 等 {@see ImageException} 子类会直接抛出，不包装）
     */
    public function open(mixed $source): ImageEditor
    {
        $manager = $this->intervention();

        try {
            if ($source instanceof \SplFileInfo) {
                $image = $this->decodeFilePath($manager, $source->getPathname());
            } elseif (is_resource($source)) {
                // 不可定位的流先复制到临时流，保证可以先读头部预检再把完整内容交给 decoder
                $stream = PixelGuard::bufferedStream($source);
                try {
                    PixelGuard::assertStream($stream, $this->config->maxPixels());
                    $image = $manager->decodeStream($stream);
                } finally {
                    if ($stream !== $source) {
                        fclose($stream);
                    }
                }
            } elseif (is_string($source) && is_file($source)) {
                $image = $this->decodeFilePath($manager, $source);
            } elseif (is_string($source)) {
                PixelGuard::assertBinary($source, $this->config->maxPixels());
                $image = $manager->decodeBinary($source);
            } else {
                throw new InvalidImageException('Unsupported image source type');
            }
        } catch (ImageException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidImageException('Unable to decode image: ' . $e->getMessage(), 0, $e);
        }

        // 解码后再复核一次：即使预检与解码之间文件被替换，也会在返回 Editor 前拦截
        PixelGuard::assertWithinLimit($image->width(), $image->height(), $this->config->maxPixels());

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

        try {
            $image = $manager->createImage($width, $height)->fill($background);
        } catch (ImageException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidImageException('Unable to create canvas: ' . $e->getMessage(), 0, $e);
        }

        return new ImageEditor($image, $this->config, $manager);
    }

    /**
     * 通过文件句柄读取并解码，避免 `getimagesize($path)` 与 `decodePath($path)` 之间的
     * 文件替换窗口（TOCTOU）：句柄一旦打开，后续读取都指向同一个 inode。
     *
     * @throws InvalidImageException 文件不可读、预检失败或解码失败
     */
    private function decodeFilePath(InterventionImageManager $manager, string $path): ImageInterface
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new InvalidImageException('Image file is not readable');
        }

        try {
            PixelGuard::assertStream($stream, $this->config->maxPixels());
            $image = $manager->decodeStream($stream);
            $image->origin()->setFilePath($path);

            return $image;
        } finally {
            fclose($stream);
        }
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
