<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Common\Library\PubSub;

use Common\Library\Cache\RedisConnection;
use Predis\PubSub\Consumer as PubSubConsumer;

class PredisPubSub extends AbstractPubSub
{
    /**
     * @var \Predis\Client
     */
    protected $redis;

    /**
     * @var PubSubConsumer
     */
    protected $pubSubConsumer;

    /**
     * RedisPubSub constructor.
     * @param $redis
     * @return void
     */
    public function __construct(RedisConnection $redis)
    {
        parent::__construct($redis);
    }

    /**
     * @param string $channel
     * @param string $message
     * @return int
     */
    public function publish(string $channel, string $message)
    {
        return $this->redis->publish($channel, $message);
    }

    /**
     * @param array $channels
     * @param $callback
     * @return mixed
     */
    public function subscribe(array $channels, $callback)
    {
        return $this->handleSubscribe($channels, $callback, false);
    }

    /**
     * @param array $patterns
     * @param $callback
     * @return mixed
     */
    public function psubscribe(array $patterns, $callback)
    {
        return $this->handleSubscribe($patterns, $callback, true);
    }

    /**
     * @param array $channels
     * @param $callback
     * @param bool $isPattern
     * @return mixed
     */
    protected function handleSubscribe(array $channels, $callback, bool $isPattern = false)
    {
        $pubSubConsumer = $this->getPubSubConsumer();
        if ($isPattern) {
            $pubSubConsumer->psubscribe(...$channels);
        } else {
            $pubSubConsumer->subscribe(...$channels);
        }

        /** @var object $message */
        foreach ($pubSubConsumer as $message) {
            $kind = $message->kind;
            $channel = $message->channel;
            $msg = $message->payload;
            if ($kind == 'message') {
                if ($this->isCoroutine) {
                    $exception = '';
                    \Swoole\Coroutine::create(function () use ($callback, $channel, $msg, & $exception) {
                        try {
                            return call_user_func($callback, $this->redis, $channel, $msg);
                        } catch (\Throwable $throwable) {
                            if (class_exists("Swoolefy\Worker\AbstractBaseWorker")) {
                                \Swoolefy\Worker\AbstractBaseWorker::getProcessInstance()->onHandleException($throwable);
                            } else {
                                $exception = $throwable;
                            }
                        }
                    });

                    if ($exception instanceof \Throwable) {
                        throw $exception;
                    }
                } else {
                    return call_user_func($callback, $this->redis, $channel, $msg);
                }

            } else if ($kind == 'pmessage') {
                $pattern = $message->pattern ?? '';

                if ($this->isCoroutine) {
                    \Swoole\Coroutine::create(function () use ($callback, $pattern, $channel, $msg) {
                        try {
                            return call_user_func($callback, $this->redis, $pattern, $channel, $msg);
                        } catch (\Exception $e) {
                            if (class_exists("Swoolefy\Worker\AbstractBaseWorker")) {
                                \Swoolefy\Worker\AbstractBaseWorker::getProcessInstance()->onHandleException($e);
                            }
                        }
                    });
                } else {
                    return call_user_func($callback, $this->redis, $pattern, $channel, $msg);
                }
            }
        }
    }

    /**
     * @return PubSubConsumer|null
     */
    protected function getPubSubConsumer()
    {
        if (!$this->pubSubConsumer) {
            $this->pubSubConsumer = $this->redis->pubSubLoop();
        }

        return $this->pubSubConsumer;
    }

    /**
     * @param array $channels
     * @return void
     */
    public function unsubscribe(array $channels)
    {
        $pubSubConsumer = $this->redis->pubSubLoop();
        $pubSubConsumer->unsubscribe(...$channels);
    }

    /**
     * @param array $patterns
     * @return void
     */
    public function punsubscribe(array $patterns)
    {
        $pubSubConsumer = $this->redis->pubSubLoop();
        $pubSubConsumer->punsubscribe(...$patterns);
    }
}