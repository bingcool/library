<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Verification;

use Swoolefy\Library\Redis\Predis;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Library\Sms\Contracts\SmsStoreInterface;
use Swoolefy\Library\Sms\Exception\InvalidConfigException;
use Swoolefy\Library\Sms\Support\RedisComponent;

/**
 * 基于库内 `Redis`（`RedisConnection` 协程连接池）的验证码存储实现。
 *
 * 只调用窄接口 `SmsStoreInterface` 需要的原生命令（set/setex/get/del/exists/ttl/eval），
 * 验证码校验用单个 Lua 脚本原子完成，避免并发重复消费与尝试次数绕过。
 * 构造参数可以是连接，也可以是 cache.php 的组件名。组件名在每次命令时解析，走当前协程里的那个组件。
 * 命令经 `RedisConnection::__call` 转发，享受库内 Redis 的断线重连 / 只读 replay。
 *
 * @see \Swoolefy\Library\Sms\Contracts\SmsStoreInterface 测试替身见 Testing\ArraySmsStore
 */
final class RedisSmsStore implements SmsStoreInterface
{
    /**
     * @param RedisConnection|string $redis 连接，或 cache.php 组件名（sms.php 的 redis_component）
     */
    public function __construct(
        private RedisConnection|string $redis,
        private string $prefix = 'sms:',
    ) {
    }

    public function put(string $key, string $value, int $ttl): void
    {
        $this->assertTtl($ttl);
        $this->redis()->setex($this->k($key), $ttl, $value);
    }

    public function add(string $key, string $value, int $ttl): bool
    {
        $this->assertTtl($ttl);
        $redis = $this->redis();
        $full = $this->k($key);
        // NX 保证同一时刻只有一个请求能占住该键
        if ($redis instanceof Predis) {
            $result = $redis->set($full, $value, 'EX', $ttl, 'NX');
        } else {
            $result = $redis->set($full, $value, ['nx', 'ex' => $ttl]);
        }

        return $this->isSuccessfulSet($result);
    }

    public function get(string $key): ?string
    {
        $value = $this->redis()->get($this->k($key));

        // 原生 \Redis 未命中返回 false；Predis 返回 null
        return ($value === false || $value === null) ? null : (string) $value;
    }

    public function forget(string $key): void
    {
        $this->redis()->del($this->k($key));
    }

    public function exists(string $key): bool
    {
        return (bool) $this->redis()->exists($this->k($key));
    }

    public function ttl(string $key): int
    {
        return (int) $this->redis()->ttl($this->k($key));
    }

    public function increment(string $key, int $ttl): int
    {
        $this->assertTtl($ttl);

        // INCR 与 TTL 修复必须原子：键已存在但无 TTL（历史数据 / 上次中断）时也要补上
        return (int) $this->evalScript($this->incrementScript(), [$this->k($key), $ttl], 1);
    }

    public function verifyCode(
        string $codeKey,
        string $attemptsKey,
        string $traceKey,
        string $cooldownKey,
        string $input,
        int $maxAttempts,
        int $attemptTtl,
        int $traceTtl,
    ): array {
        $this->assertTtl($attemptTtl);
        $this->assertTtl($traceTtl);

        $raw = $this->evalScript($this->verifyScript(), [
            $this->k($codeKey),
            $this->k($attemptsKey),
            $this->k($traceKey),
            $this->k($cooldownKey),
            $input,
            $maxAttempts,
            $attemptTtl,
            $traceTtl,
        ], 4);

        if (!is_array($raw) || !isset($raw[0])) {
            throw new \RuntimeException('Unexpected Redis verify result');
        }

        $remaining = isset($raw[1]) ? (int) $raw[1] : null;
        if ($remaining !== null && $remaining < 0) {
            $remaining = null;
        }

        return ['status' => (string) $raw[0], 'remaining' => $remaining];
    }

    /**
     * Predis 与 phpredis / RedisCluster 的 eval 参数顺序不同。
     *
     * @param list<string|int> $keysAndArgs [KEYS..., ARGV...]
     */
    private function evalScript(string $script, array $keysAndArgs, int $numKeys)
    {
        $redis = $this->redis();

        if ($redis instanceof Predis) {
            return $redis->eval($script, $numKeys, ...$keysAndArgs);
        }

        return $redis->eval($script, $keysAndArgs, $numKeys);
    }

    /**
     * phpredis 成功时返回连接对象（无 __toString），失败返回 false；Predis 返回 Status 对象。
     */
    private function isSuccessfulSet(mixed $result): bool
    {
        if ($result === true || $result === 'OK') {
            return true;
        }

        if (is_object($result)) {
            if (method_exists($result, '__toString')) {
                return (string) $result === 'OK';
            }

            return $result instanceof \Redis || $result instanceof \RedisCluster;
        }

        return false;
    }

    private function incrementScript(): string
    {
        return <<<'LUA'
local count = redis.call('INCR', KEYS[1])
local ttl = redis.call('TTL', KEYS[1])
if ttl < 0 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return count
LUA;
    }

    /**
     * 单个 Lua 脚本内完成读取、比较、消费、计数、锁定与成功清理，避免 TOCTOU。
     * 脚本内为 Redis 字符串比较，不做常量时间比较；网络与 Redis 内部开销远大于比较耗时。
     */
    private function verifyScript(): string
    {
        return <<<'LUA'
local codeKey = KEYS[1]
local attemptsKey = KEYS[2]
local traceKey = KEYS[3]
local cooldownKey = KEYS[4]

local input = ARGV[1]
local maxAttempts = tonumber(ARGV[2])
local attemptTtl = tonumber(ARGV[3])
local traceTtl = tonumber(ARGV[4])

local stored = redis.call('GET', codeKey)
if not stored then
    local trace = redis.call('GET', traceKey)
    if trace == 'locked' then
        return {'locked', 0}
    end
    if trace == 'active' then
        return {'expired', -1}
    end
    return {'absent', -1}
end

if input ~= '' and stored == input then
    redis.call('DEL', codeKey)
    redis.call('DEL', attemptsKey)
    redis.call('DEL', cooldownKey)
    redis.call('DEL', traceKey)
    return {'ok', -1}
end

local attempts = redis.call('INCR', attemptsKey)
if attempts == 1 then
    redis.call('EXPIRE', attemptsKey, attemptTtl)
end

if maxAttempts > 0 and attempts >= maxAttempts then
    redis.call('DEL', codeKey)
    redis.call('SET', traceKey, 'locked', 'EX', traceTtl)
    return {'locked', 0}
end

if maxAttempts > 0 then
    return {'mismatch', maxAttempts - attempts}
end
return {'mismatch', -1}
LUA;
    }

    /**
     * OTP / 冷却 / 计数键必须有明确过期时间，禁止静默写入永久键。
     */
    private function assertTtl(int $ttl): void
    {
        if ($ttl < 1) {
            throw new InvalidConfigException('Sms store ttl must be >= 1 second');
        }
    }

    private function redis(): RedisConnection
    {
        if ($this->redis instanceof RedisConnection) {
            return $this->redis;
        }

        return RedisComponent::connection($this->redis);
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }
}
