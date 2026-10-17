<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Verification;

use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Library\Sms\Contracts\SmsStoreInterface;

/**
 * 基于库内 `Redis`（`RedisConnection` 协程连接池）的验证码存储实现。
 *
 * 只调用窄接口 `SmsStoreInterface` 需要的原生命令（setex/get/del/exists/incr/expire），
 * 全部经 `RedisConnection::__call` 转发，天然享受库内 Redis 的断线重连 / 只读 replay 策略。
 *
 * @see \Swoolefy\Library\Sms\Contracts\SmsStoreInterface 测试替身见 Testing\ArraySmsStore
 */
final class RedisSmsStore implements SmsStoreInterface
{
    public function __construct(
        private RedisConnection $redis,
        private string $prefix = 'sms:',
    ) {
    }

    public function put(string $key, string $value, int $ttl): void
    {
        // setex 语义：写入并设置过期；ttl<=0 时退化为不过期（防御配置错误）
        if ($ttl > 0) {
            $this->redis->setex($this->k($key), $ttl, $value);
        } else {
            $this->redis->set($this->k($key), $value);
        }
    }

    public function get(string $key): ?string
    {
        $value = $this->redis->get($this->k($key));

        // 原生 \Redis 未命中返回 false；Predis 返回 null
        return ($value === false || $value === null) ? null : (string) $value;
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->k($key));
    }

    public function exists(string $key): bool
    {
        return (bool) $this->redis->exists($this->k($key));
    }

    public function increment(string $key, int $ttl): int
    {
        $full = $this->k($key);
        $count = (int) $this->redis->incr($full);

        // 首次创建（=1）时补 TTL，避免计数键永不过期
        if ($count === 1 && $ttl > 0) {
            $this->redis->expire($full, $ttl);
        }

        return $count;
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }
}
