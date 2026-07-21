<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem;

use Swoolefy\Library\FileStorageSystem\Config\FileStorageConfig;
use Swoolefy\Library\FileStorageSystem\Exception\InvalidProviderException;

/**
 * Disk 管理器：可按 provider 名缓存 FileDisk；禁止缓存临时 Token / 请求态。
 */
final class FileStorageManager
{
    /** @var array<string, FileDisk> 仅缓存 Disk 实例，STS 刷新由 Provider 自行处理 */
    private array $disks = [];

    private FileStorageFactory $factory;

    /**
     * @param array<string, mixed>|FileStorageConfig $config 完整配置或已解析的 FileStorageConfig
     * @param FileStorageFactory|null $factory 可注入工厂便于单测
     */
    public function __construct(
        private array|FileStorageConfig $config,
        ?FileStorageFactory $factory = null,
    ) {
        if (is_array($this->config)) {
            $this->config = FileStorageConfig::fromArray($this->config);
        }
        $this->factory = $factory ?? new FileStorageFactory();
    }

    /**
     * 按 provider 名返回 FileDisk；首次创建后缓存在本 Manager，不缓存 STS/请求态凭证。
     *
     * @param string|null $name provider 名，null 使用 default_provider
     * @return FileDisk
     */
    public function disk(?string $name = null): FileDisk
    {
        $name = $name ?? $this->config->defaultProvider();
        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }
        if (!$this->config->hasProvider($name)) {
            throw new InvalidProviderException("File storage provider [{$name}] is not configured");
        }
        $disk = $this->factory->make($name, $this->config->provider($name));
        $this->disks[$name] = $disk;

        return $disk;
    }

    /**
     * 仅用于测试：清空已缓存 Disk（不涉及 StsCredentialProvider 内凭证缓存）。
     */
    public function flushDisks(): void
    {
        $this->disks = [];
    }

    /**
     * 返回当前持有的配置快照（供调试或二次读取 provider 配置）。
     *
     * @return FileStorageConfig
     */
    public function config(): FileStorageConfig
    {
        return $this->config;
    }
}
