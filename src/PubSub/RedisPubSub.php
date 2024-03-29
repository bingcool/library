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

use Common\Library\Redis\RedisConnection;

class RedisPubSub extends AbstractPubSub
{
    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * RedisPubSub constructor.
     * @param $redis
     * @return void
     */
    public function __construct(RedisConnection $redis)
    {
        parent::__construct($redis);
        // no timeout
        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
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
        return $this->handleSubscribe($channels, $callback);
    }

    /**
     * @param array $channels
     * @param $callback
     * @return mixed
     */
    protected function handleSubscribe(array $channels, $callback)
    {
        if ($this->isCoroutine) {
            $this->redis->subscribe($channels, function ($redis, $chan, $msg) use ($callback) {
               goApp(function () use ($callback, $redis, $chan, $msg) {
                   return call_user_func($callback, $redis, $chan, $msg);
               });
            });
        } else {
            $this->redis->subscribe($channels, function ($redis, $chan, $msg) use ($callback) {
                return call_user_func($callback, $redis, $chan, $msg);
            });
        }
    }

    /**
     * @param array $patterns
     * @param $callback
     * @return mixed
     */
    public function psubscribe(array $patterns, $callback)
    {
        if ($this->isCoroutine) {
            $this->redis->psubscribe($patterns, function ($redis, $pattern, $chan, $msg) use ($callback) {
                goApp(function () use ($callback, $redis, $pattern, $chan, $msg) {
                    return call_user_func($callback, $redis, $pattern, $chan, $msg);
                });
            });
        } else {
            $this->redis->psubscribe($patterns, function ($redis, $pattern, $chan, $msg) use ($callback) {
                return call_user_func($callback, $redis, $pattern, $chan, $msg);
            });
        }
    }

    /**
     * @param array $channels
     * @return void
     */
    public function unsubscribe(array $channels)
    {
        $this->redis->unsubscribe($channels);
    }

    /**
     * @param array $patterns
     * @return void
     */
    public function punsubscribe(array $patterns)
    {
        $this->redis->punsubscribe($patterns);
    }
}