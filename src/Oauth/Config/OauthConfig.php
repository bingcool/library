<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth\Config;

/**
 * oauth.php 配置段的只读访问器。
 *
 * 设计动机：业务与 Factory 不直接碰原始数组键，统一 default / providers / hasProvider 语义，
 * 并兼容外层多包一层 `oauth` 键（与 FileStorageConfig 对 `file_storage_system` 的处理一致）。
 */
final class OauthConfig
{
    /**
     * @param array{
     *   oauth_providers?: array<string, array<string, mixed>>
     * } $config 已剥离外层 oauth 键后的配置体
     */
    public function __construct(
        private array $config,
    ) {
    }

    /**
     * 从应用配置数组解析。
     *
     * 支持两种形态：
     * 1) 顶层即 oauth_providers（stub / include 直接返回）
     * 2) 外层包裹 ['oauth' => [...]]，便于合并进更大配置树
     *
     * 登录必须显式指定 provider 名，本配置不再提供 default_provider。
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['oauth']) && is_array($config['oauth'])) {
            $config = $config['oauth'];
        }

        return new self($config);
    }

    /**
     * 全部已声明的 oauth_providers（键名为业务侧引用名，值含 driver 等）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function providers(): array
    {
        $providers = $this->config['oauth_providers'] ?? [];

        return is_array($providers) ? $providers : [];
    }

    /**
     * 单个 provider 的配置块；不存在或非数组时返回 []（配合 hasProvider 判断）。
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
     *
     * 注意：空数组 [] 视为未配置，避免误创建无 driver 的 Client。
     */
    public function hasProvider(string $name): bool
    {
        return $this->provider($name) !== [];
    }
}
