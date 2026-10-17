<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Config;

/**
 * sms.php 配置段的只读访问器。
 *
 * 与 `OauthConfig` / `FileStorageConfig` 一致：`fromArray()` 兼容外层再包一层 `sms` 键；
 * 业务与 Factory 不直接碰原始数组，统一 defaultGateway / redisComponent / hasGateway / gateway 语义。
 *
 * 注意：类名命名空间为 `Swoolefy\Library\Sms\Config\SmsConfig`，但方案里以 `SmsConfig` 直呼；
 * 通过 `Sms` 根命名空间下的类型别名（use）引用即可。
 */
final class SmsConfig
{
    /**
     * @param array<string, mixed> $config 已剥离外层 sms 键后的配置体
     */
    public function __construct(
        private array $config,
    ) {
    }

    /**
     * 从应用配置数组解析。支持：
     * 1) 顶层即 default_gateway / gateways（stub 直接返回）
     * 2) 外层包裹 ['sms' => [...]]
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['sms']) && is_array($config['sms'])) {
            $config = $config['sms'];
        }

        return new self($config);
    }

    /**
     * 默认通道名。未显式配置时取 gateways 的第一个键；都没有则 'log'。
     */
    public function defaultGateway(): string
    {
        $default = (string) ($this->config['default_gateway'] ?? '');
        if ($default !== '') {
            return $default;
        }

        $gateways = $this->gateways();

        return (string) (array_key_first($gateways) ?? 'log');
    }

    /**
     * 验证码与限流使用的 Redis 组件名，对应 Config/component/cache.php 的键。
     * 未配置时用 cache.php 里的默认组件名 cache。
     */
    public function redisComponent(): string
    {
        $name = trim((string) ($this->config['redis_component'] ?? ''));

        return $name !== '' ? $name : 'cache';
    }

    /**
     * 全部已声明的 gateways（键名=业务引用名，值含 gateway 等）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function gateways(): array
    {
        $gateways = $this->config['gateways'] ?? [];

        return is_array($gateways) ? $gateways : [];
    }

    /**
     * 单个 gateway 配置块；不存在或非数组时返回 []（配合 hasGateway）。
     *
     * @return array<string, mixed>
     */
    public function gateway(string $name): array
    {
        $gateways = $this->gateways();
        if (!isset($gateways[$name]) || !is_array($gateways[$name])) {
            return [];
        }

        return $gateways[$name];
    }

    /**
     * gateway 是否已声明且非空。
     */
    public function hasGateway(string $name): bool
    {
        return $this->gateway($name) !== [];
    }

    /**
     * 验证码策略配置块。
     *
     * @return array<string, mixed>
     */
    public function verification(): array
    {
        $v = $this->config['verification'] ?? [];

        return is_array($v) ? $v : [];
    }
}
