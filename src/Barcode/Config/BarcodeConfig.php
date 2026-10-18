<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode\Config;

/**
 * `Config/barcode.php` 只读访问器。
 *
 * 支持传入整包配置（含 `barcode` 外层键）或已剥离的内层数组。
 */
final class BarcodeConfig
{
    /**
     * @param array<string, mixed> $config 已剥离外层 barcode 键的配置段
     */
    public function __construct(private array $config)
    {
    }

    /**
     * 从应用配置数组构建；若存在 `barcode` 键则自动下钻。
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['barcode']) && is_array($config['barcode'])) {
            $config = $config['barcode'];
        }

        return new self($config);
    }

    /**
     * default 段：type、height、width、show_text、format 等 Builder 初始值。
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $default = $this->config['default'] ?? [];

        return is_array($default) ? $default : [];
    }
}
