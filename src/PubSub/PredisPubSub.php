<?php

declare(strict_types=1);

namespace Swoolefy\Library\PubSub;

use Predis\Consumer\PubSub\Consumer as PubSubConsumer;
use Swoolefy\Library\PubSub\Exception\PubSubException;
use Swoolefy\Library\Redis\Predis as PredisConnection;
use Swoolefy\Library\Redis\RedisConnection;

/**
 * Predis 的 PubSub 驱动（predis/predis ~3.4，实测 v3.7.0）。
 *
 * 与 phpredis 驱动共享 AbstractPubSub 的所有权与派发语义，另外处理三处 Predis 特性：
 * 1. `Client::pubSubLoop()` 每次都新建 Consumer，退订必须复用**当前活动**的 Consumer，
 *    否则服务端返回的 unsubscribe 确认没人读取，会滞留在连接缓冲区、让后续命令整体错位一条；
 * 2. 订阅数归零时 Consumer 自身 `invalidate()` 且永不复位，全量退订后必须丢弃它重建，
 *    否则重订阅只写命令、`foreach` 一次都不进入；
 * 3. 连接参数缺省 `read_write_timeout` 时读取沿用 `default_socket_timeout`，空闲订阅会超时终止。
 *
 * @see PubSubConsumer
 */
class PredisPubSub extends AbstractPubSub
{
    /** 0 或负数在 Predis 的 StreamFactory 里等价于「不设读写超时」 */
    private const NO_READ_TIMEOUT = -1.0;

    /**
     * 活动订阅会话；循环结束或全量退订后置空，下一次订阅重建。
     *
     * @var PubSubConsumer|null
     */
    private $consumer = null;

    public function __construct(RedisConnection $redis, array $options = [])
    {
        if (!$redis instanceof PredisConnection) {
            throw new PubSubException('PredisPubSub 需要 Swoolefy\Library\Redis\Predis 连接');
        }

        parent::__construct($redis, $options);
        $this->disableReadTimeout($redis);
    }

    /**
     * @return int|false
     */
    protected function doPublish(string $channel, string $message)
    {
        return $this->redis->publish($channel, $message);
    }

    /**
     * @param string[] $names
     */
    protected function openLoop(array $names, callable $handler, bool $isPattern): void
    {
        $consumer = $this->activeConsumer();
        if ($isPattern) {
            $consumer->psubscribe(...$names);
        } else {
            $consumer->subscribe(...$names);
        }

        try {
            foreach ($consumer as $message) {
                $kind = (string) ($message->kind ?? '');
                if ($kind === 'message') {
                    if (!$this->handleMessage((string) $message->channel, (string) $message->payload)) {
                        $this->closeLoop();
                    }
                } elseif ($kind === 'pmessage') {
                    if (!$this->handleMessage((string) $message->channel, (string) $message->payload, (string) ($message->pattern ?? ''))) {
                        $this->closeLoop();
                    }
                }
            }
        } finally {
            // 循环返回即会话结束：Consumer 可能已被 invalidate，留着它下次订阅会静默失效
            $this->consumer = null;
        }
    }

    /**
     * @param string[] $names
     */
    protected function doUnsubscribe(array $names, bool $isPattern): void
    {
        $consumer = $this->activeConsumer();
        if ($isPattern) {
            $consumer->punsubscribe(...$names);
        } else {
            $consumer->unsubscribe(...$names);
        }

        // Consumer 的 unsubscribe 只写不读，确认必须由同一条连接读干净，否则残留污染后续命令
        $this->drainConfirmations($consumer, $isPattern ? 'punsubscribe' : 'unsubscribe', count($names));
        $this->forgetNames($names, $isPattern);

        if (!$this->isSubscribed()) {
            $this->consumer = null;
        }
    }

    private function activeConsumer(): PubSubConsumer
    {
        if ($this->consumer === null) {
            $this->consumer = $this->redis->pubSubLoop();
        }

        return $this->consumer;
    }

    /**
     * 读取退订确认。期间到达的业务消息照常派发，不因退订而丢消息。
     */
    private function drainConfirmations(PubSubConsumer $consumer, string $kind, int $expected): void
    {
        $seen = 0;
        $guard = 0;
        while ($seen < $expected && $guard++ < $expected * 16 + 16) {
            $message = $consumer->current();
            $type = (string) ($message->kind ?? '');
            if ($type === $kind) {
                $seen++;
                continue;
            }

            if ($type === 'message') {
                $this->handleMessage((string) $message->channel, (string) $message->payload);
                continue;
            }

            if ($type === 'pmessage') {
                $this->handleMessage((string) $message->channel, (string) $message->payload, (string) ($message->pattern ?? ''));
            }
        }
    }

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
     * 订阅连接不能被空闲超时打断：参数缺失或为正数时重建 Client 并带上无限读超时。
     */
    private function disableReadTimeout(PredisConnection $redis): void
    {
        $parameters = (array) $redis->getParameters();
        $current = $parameters['read_write_timeout'] ?? null;
        if ($current !== null && (float) $current <= 0) {
            return;
        }

        $parameters['read_write_timeout'] = self::NO_READ_TIMEOUT;
        $redis->applyParameters($parameters);
    }
}
