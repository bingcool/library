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
 * 收窄到这些原语后，替换存储后端与单测替身都只需实现本接口。
 */
interface SmsStoreInterface
{
    /**
     * 写入带 TTL 的字符串值。已存在则覆盖。
     */
    public function put(string $key, string $value, int $ttl): void;

    /**
     * 仅当键不存在时写入。Redis SET NX，用于并发占住冷却位。
     *
     * @return bool true 表示本次占到；false 表示已有其它请求占住
     */
    public function add(string $key, string $value, int $ttl): bool;

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
     * 剩余存活秒数。
     *
     * @return int 剩余秒数；-1 表示键存在但无过期时间；-2 表示键不存在或已过期
     */
    public function ttl(string $key): int;

    /**
     * 原子自增并返回自增后的值；首次创建时施加 TTL，已有键缺失 TTL 时补上。
     */
    public function increment(string $key, int $ttl): int;

    /**
     * 原子校验并消费验证码：读取、比较、计数、锁定和成功清理必须在同一原子操作内完成，
     * 保证并发下同一验证码至多成功一次，且进入比较的失败次数不超过 maxAttempts。
     *
     * - 码不存在：按 trace 返回 expired / absent / locked；
     * - 码匹配：删除 code / attempts / cooldown / trace，返回 ok；
     * - 码不匹配：原子自增 attempts；达到 maxAttempts 时删除 code 并写入 locked trace。
     *
     * @param string $input 已 trim 的用户输入
     * @param int $maxAttempts <=0 表示不限制错误次数
     * @param int $attemptTtl attempts 首次创建时的 TTL（秒）
     * @param int $traceTtl 锁定痕迹 TTL（秒）
     * @return array{status: string, remaining: int|null} status 取 VerifyResult::STATUS_* 之一
     */
    public function verifyCode(
        string $codeKey,
        string $attemptsKey,
        string $traceKey,
        string $cooldownKey,
        string $input,
        int $maxAttempts,
        int $attemptTtl,
        int $traceTtl,
    ): array;
}
