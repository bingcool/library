<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Contracts;

/**
 * 验证码存储的窄接口：只暴露 SmsVerifier 真正需要的原语。
 *
 * 生产实现 `RedisSmsStore` 基于库内 `Redis`（`RedisConnection`，协程连接池）；
 * 测试实现 `Testing\ArraySmsStore` 用内存数组，无 IO、可确定性断言。
 *
 * 之所以不直接依赖 `\Redis` / `CacheInterface`：二者方法面过大且语义不一（increment / TTL 缺失），
 * 收窄到 5 个原语后，替换存储后端与单测替身都只需实现本接口。
 */
interface SmsStoreInterface
{
    /**
     * 写入带 TTL 的字符串值。
     */
    public function put(string $key, string $value, int $ttl): void;

    /**
     * 读取；不存在返回 null。
     */
    public function get(string $key): ?string;

    /**
     * 删除键（幂等，不存在不报错）。
     */
    public function forget(string $key): void;

    /**
     * 键是否存在且非空。
     */
    public function exists(string $key): bool;

    /**
     * 原子自增并返回自增后的值；首次创建时施加 TTL。用于失败次数计数。
     */
    public function increment(string $key, int $ttl): int;
}
