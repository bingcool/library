<?php

declare(strict_types=1);

namespace Swoolefy\Library\PubSub;

use Swoolefy\Library\PubSub\Exception\PubSubException;
use Swoolefy\Library\Redis\Redis as PhpRedisConnection;
use Swoolefy\Library\Redis\RedisConnection;

/**
 * phpredis 扩展的 PubSub 驱动。
 *
 * 两条实测约束决定了本驱动的写法：
 * 1. 回调返回 `false` 不会「优雅停止」，phpredis 会直接断开连接并抛
 *    `RedisException: read error on connection`（非协程下 exit 255）。
 *    停止循环只能在回调内对同一连接显式 `unsubscribe()/punsubscribe()`。
 * 2. 订阅中的连接被 Swoole 绑定到发起 `subscribe()` 的协程，跨协程读写它会致命错误，
 *    因此回调只拿渠道与消息体，连接对象一律不下发给业务。
 *
 * @see \Redis
 */
class RedisPubSub extends AbstractPubSub
{
    public function __construct(RedisConnection $redis, array $options = [])
    {
        if (!extension_loaded('redis')) {
            throw new PubSubException('RedisPubSub 需要 phpredis 扩展');
        }

        if (!$redis instanceof PhpRedisConnection) {
            throw new PubSubException('RedisPubSub 需要 Swoolefy\Library\Redis\Redis 连接');
        }

        parent::__construct($redis, $options);
    }

    /**
     * @return int|false
     */
    protected function doPublish(string $channel, string $message)
    {
        return $this->redis->publish($channel, $message);
    }

    /**
     * 进入 phpredis 的阻塞订阅循环，读超时仅在循环期间关闭，退出后原样恢复。
     *
     * @param string[] $names
     */
    protected function openLoop(array $names, callable $handler, bool $isPattern): void
    {
        $previousReadTimeout = $this->suspendReadTimeout();

        try {
            if ($isPattern) {
                $this->redis->psubscribe($names, $this->nativeCallback());
            } else {
                $this->redis->subscribe($names, $this->nativeCallback());
            }
        } finally {
            $this->restoreReadTimeout($previousReadTimeout);
        }
    }

    /**
     * @param string[] $names
     */
    protected function doUnsubscribe(array $names, bool $isPattern): void
    {
        if ($isPattern) {
            $this->redis->punsubscribe($names);
        } else {
            $this->redis->unsubscribe($names);
        }

        $this->forgetNames($names, $isPattern);
    }

    /**
     * phpredis 回调：形如 `($redis, $channel, $payload)` 与 `($redis, $pattern, $channel, $payload)`。
     *
     * 恒返回 true，停止靠显式退订（见类注释）。
     */
    private function nativeCallback(): callable
    {
        return function (...$args): bool {
            $payload = array_slice($args, 1);
            if (count($payload) === 3) {
                [$pattern, $channel, $body] = $payload;
                $continue = $this->handleMessage((string) $channel, (string) $body, (string) $pattern);
            } elseif (count($payload) === 2) {
                [$channel, $body] = $payload;
                $continue = $this->handleMessage((string) $channel, (string) $body);
            } else {
                $continue = true;
            }

            if (!$continue) {
                $this->closeLoop();
            }

            return true;
        };
    }

    /**
     * 结束循环：把本会话剩下的订阅全部退掉，phpredis 读不到订阅才返回。
     */
    private function closeLoop(): void
    {
        $channels = $this->subscribedChannels();
        if ($channels !== []) {
            $this->doUnsubscribe($channels, false);
        }

        $patterns = $this->subscribedPatterns();
        if ($patterns !== []) {
            $this->doUnsubscribe($patterns, true);
        }
    }

    /**
     * 循环期间把读超时设为无限，避免空闲订阅被判死；返回原值供恢复。
     *
     * @return float|null 无需改动时返回 null
     */
    private function suspendReadTimeout(): ?float
    {
        $current = (float) $this->redis->getOption(\Redis::OPT_READ_TIMEOUT);
        if ($current === -1.0) {
            return null;
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

        return $current;
    }

    private function restoreReadTimeout(?float $previous): void
    {
        if ($previous === null) {
            return;
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, $previous);
    }
}
