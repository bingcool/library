<?php
/**
+----------------------------------------------------------------------
| Common library of swoole
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
 */

namespace Common\Library\PubSub;

Abstract class AbstractPubSub
{
    /**
     * @var mixed
     */
    protected $redis;

    /**
     * @var bool
     */
    protected $isCoroutine = false;

    /**
     * AbstractPubSub constructor.
     */
    public function __construct($redis)
    {
        $this->redis = $redis;
        $this->isCoroutine();
    }

    /**
     * @return mixed
     */
    public function getRedis()
    {
        return $this->redis;
    }

    /**
     * isCoroutine
     */
    protected function isCoroutine()
    {
        if(class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0)
        {
            $this->isCoroutine = true;
        }
    }

    abstract public function publish(string $channel, string $message);

    abstract public function subscribe(array $channels, $callback);

    abstract public function psubscribe(array $patterns, $callback);

    abstract public function unsubscribe(array $channels);

    abstract public function punsubscribe(array $patterns);
}