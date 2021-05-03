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

class RedisPubSub extends AbstractPubSub
{
    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * RedisPubSub constructor.
     * @param $redis
     */
    public function __construct($redis)
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
     */
    public function subscribe(array $channels, $callback)
    {
        return $this->handleSubscribe($channels, $callback);
    }

    /**
     * @param array $channels
     * @param $callback
     */
    protected function handleSubscribe(array $channels, $callback)
    {
        if($this->isCoroutine)
        {
            $exception = '';
            $this->redis->subscribe($channels, function ($redis, $chan, $msg) use($callback, & $exception)
            {
                \Swoole\Coroutine::create(function () use($callback, $redis, $chan, $msg, & $exception)
                {
                    try
                    {
                        return call_user_func($callback, $redis, $chan, $msg);
                    }catch (\Throwable $throwable)
                    {
                        if(class_exists("Workerfy\\AbstractProcess"))
                        {
                            \Workerfy\AbstractProcess::getProcessInstance()->onHandleException($throwable);
                        }else
                        {
                            $exception = $throwable;
                        }
                    }
                });

                if($exception instanceof \Throwable)
                {
                    throw $exception;
                }

            });

        }else {
            $this->redis->subscribe($channels, function ($redis, $chan, $msg) use($callback)
            {
                return call_user_func($callback, $redis, $chan, $msg);
            });
        }
    }

    /**
     * @param array $patterns
     * @param $callback
     */
    public function psubscribe(array $patterns, $callback)
    {
        if($this->isCoroutine)
        {
            $this->redis->psubscribe($patterns, function ($redis, $pattern, $chan, $msg) use($callback)
            {
                \Swoole\Coroutine::create(function () use($callback, $redis, $pattern, $chan, $msg)
                {
                    try
                    {
                        return call_user_func($callback, $redis, $pattern, $chan, $msg);
                    }catch (\Exception $e)
                    {
                        if(class_exists("Workerfy\\AbstractProcess"))
                        {
                            \Workerfy\AbstractProcess::getProcessInstance()->onHandleException($e);
                        }
                    }
                });
            });

        }else {
            $this->redis->psubscribe($patterns, function ($redis, $pattern, $chan, $msg) use($callback)
            {
                return call_user_func($callback, $redis, $pattern, $chan, $msg);
            });
        }
    }

    /**
     * @param array $channels
     */
    public function unsubscribe(array $channels)
    {
        $this->redis->unsubscribe($channels);
    }

    /**
     * @param array $patterns
     */
    public function punsubscribe(array $patterns)
    {
        $this->redis->punsubscribe($patterns);
    }
}