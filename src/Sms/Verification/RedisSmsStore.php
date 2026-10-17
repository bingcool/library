<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Verification;

use Swoolefy\Library\Redis\Predis;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Library\Sms\Contracts\SmsStoreInterface;
use Swoolefy\Library\Sms\Support\RedisComponent;

/**
 * 基于库内 `Redis`（`RedisConnection` 协程连接池）的验证码存储实现。
 *
 * 只调用窄接口 `SmsStoreInterface` 需要的原生命令（set/setex/get/del/exists/incr/expire），
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
        $redis = $this->redis();
        // setex 语义：写入并设置过期；ttl<=0 时退化为不过期（防御配置错误）
        if ($ttl > 0) {
            $redis->setex($this->k($key), $ttl, $value);
        } else {
            $redis->set($this->k($key), $value);
        }
    }

    public function add(string $key, string $value, int $ttl): bool
    {
        $redis = $this->redis();
        $full = $this->k($key);
        // NX 保证同一时刻只有一个请求能占住该键
        if ($redis instanceof Predis) {
            $result = $ttl > 0
                ? $redis->set($full, $value, 'EX', $ttl, 'NX')
                : $redis->set($full, $value, 'NX');
        } else {
            $result = $ttl > 0
                ? $redis->set($full, $value, ['nx', 'ex' => $ttl])
                : $redis->set($full, $value, ['nx']);
        }

        return $result === true || $result === 'OK' || (is_object($result) && (string) $result === 'OK');
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

    public function increment(string $key, int $ttl): int
    {
        $redis = $this->redis();
        $full = $this->k($key);
        $count = (int) $redis->incr($full);

        // 首次创建（=1）时补 TTL，避免计数键永不过期
        if ($count === 1 && $ttl > 0) {
            $redis->expire($full, $ttl);
        }

        return $count;
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
