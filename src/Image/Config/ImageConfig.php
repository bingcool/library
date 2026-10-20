<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Config;

use Swoolefy\Library\Image\Exception\InvalidConfigException;
use Swoolefy\Library\Image\Exception\InvalidImageException;

/**
 * `Config/image.php` 只读访问器。{@see fromArray()} 兼容外层再包一层 `image` 键。
 */
final class ImageConfig
{
    /**
     * @param array<string, mixed> $config 已剥离外层 `image` 键的配置体
     */
    public function __construct(private array $config)
    {
    }

    /**
     * @param array<string, mixed> $config 完整配置或仅 image 段
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['image']) && is_array($config['image'])) {
            $config = $config['image'];
        }

        return new self($config);
    }

    /**
     * gd | imagick。
     *
     * 非法值不再静默回退 gd：写错 driver 会让 Imagick 专属格式/能力被隐藏，
     * 因此直接抛配置异常，在首次构建 Manager 时暴露。
     *
     * @throws InvalidConfigException 配置值不是 gd/imagick
     */
    public function driver(): string
    {
        $driver = strtolower(trim((string) ($this->config['driver'] ?? 'gd')));
        if (!in_array($driver, ['gd', 'imagick'], true)) {
            throw new InvalidConfigException(sprintf(
                'Unknown image driver [%s]; expected gd|imagick',
                $driver
            ));
        }

        return $driver;
    }

    /**
     * 相对路径的基准目录。
     *
     * 优先级：`base_path` 配置 > 框架 `APP_PATH` 常量 > 进程 CWD。
     * 水印 logo / 字体等相对路径均以此为基准，避免不同 SAPI 的 CWD 差异导致找不到文件。
     */
    public function basePath(): string
    {
        $base = $this->config['base_path'] ?? null;
        if (is_string($base) && trim($base) !== '') {
            $real = realpath($base);

            return $real !== false ? $real : rtrim($base, '/\\');
        }

        if (defined('APP_PATH')) {
            return rtrim((string) constant('APP_PATH'), '/\\');
        }

        return getcwd() ?: '.';
    }

    /** 单张图允许的最大像素数（宽×高），0 表示不限制 */
    public function maxPixels(): int
    {
        return max(0, (int) ($this->config['max_pixels'] ?? 40_000_000));
    }

    /**
     * 默认编码参数：format、quality、progressive、strip、max_bytes 等。
     *
     * @return array<string, mixed>
     */
    public function encodeDefaults(): array
    {
        $encode = $this->config['encode'] ?? [];

        return is_array($encode) ? $encode : [];
    }

    /**
     * `watermark.image` 段 typed 访问。
     *
     * 未显式配置时，`base_path` 取 {@see basePath()}，`allowed_roots` 默认只允许该目录，
     * 从而让 `ImageWatermark::fromConfig()` 默认拒绝读取项目目录之外的本地文件。
     */
    public function watermarkImage(): ImageWatermarkConfig
    {
        $watermark = $this->config['watermark']['image'] ?? [];
        $watermark = is_array($watermark) ? $watermark : [];
        $watermark['base_path'] ??= $this->basePath();
        $watermark['allowed_roots'] ??= [$this->basePath()];

        return ImageWatermarkConfig::fromArray($watermark);
    }

    /** `watermark.text` 段 typed 访问；相对字体路径以 {@see basePath()} 为基准 */
    public function watermarkText(): TextWatermarkConfig
    {
        $watermark = $this->config['watermark']['text'] ?? [];
        $watermark = is_array($watermark) ? $watermark : [];
        $watermark['base_path'] ??= $this->basePath();

        return TextWatermarkConfig::fromArray($watermark);
    }

    /** 整个 `presets` 段 */
    public function presets(): ImagePresetsConfig
    {
        $presets = $this->config['presets'] ?? [];

        return ImagePresetsConfig::fromArray(is_array($presets) ? $presets : []);
    }

    /**
     * 按名取单条预设。
     *
     * @throws InvalidImageException 预设名不存在
     */
    public function preset(string $name): ImagePresetConfig
    {
        return $this->presets()->get($name);
    }
}
