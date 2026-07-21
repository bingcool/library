<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Config;

/**
 * file_storage_system 配置段的只读访问器。
 */
final class FileStorageConfig
{
    /**
     * @param array{
     *   default_provider?: string,
     *   file_system_providers?: array<string, array<string, mixed>>
     * } $config
     */
    public function __construct(
        private array $config,
    ) {
    }

    /**
     * 从应用配置数组解析；支持外层包裹 file_storage_system 键。
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['file_storage_system']) && is_array($config['file_storage_system'])) {
            $config = $config['file_storage_system'];
        }

        return new self($config);
    }

    /**
     * 默认 provider 名，未配置时为 local。
     *
     * @return string
     */
    public function defaultProvider(): string
    {
        return (string) ($this->config['default_provider'] ?? 'local');
    }

    /**
     * 全部已声明的 file_system_providers。
     *
     * @return array<string, array<string, mixed>>
     */
    public function providers(): array
    {
        $providers = $this->config['file_system_providers'] ?? [];

        return is_array($providers) ? $providers : [];
    }

    /**
     * 单个 provider 的配置块；不存在时返回空数组（hasProvider 据此判断）。
     *
     * @return array<string, mixed>
     */
    public function provider(string $name): array
    {
        $providers = $this->providers();
        if (!isset($providers[$name]) || !is_array($providers[$name])) {
            return [];
        }

        return $providers[$name];
    }

    /**
     * provider 是否在配置中声明且为非空数组。
     */
    public function hasProvider(string $name): bool
    {
        return $this->provider($name) !== [];
    }
}
