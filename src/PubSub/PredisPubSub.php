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
     */
    public function __construct($redis)
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
     */
    public function subscribe(array $channels, $callback)
    {
       return $this->handleSubscribe($channels, $callback, false);
    }

    /**
     * @param array $patterns
     * @param $callback
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
        if($this->isCoroutine)
        {
            \Swoole\Coroutine::create(function () use($channels, $callback, $isPattern)
            {
                try
                {
                    return $this->handleMessage($this->getPubSubConsumer(), $channels, $callback, $isPattern);
                }catch (\Exception $e)
                {
                    if(class_exists("Workerfy\\AbstractProcess"))
                    {
                        \Workerfy\AbstractProcess::getProcessInstance()->onHandleException($e);
                    }
                }
            });

        }else {
            return $this->handleMessage($this->getPubSubConsumer(), $channels, $callback, $isPattern);
        }
    }

    /**
     * @param PubSubConsumer $pubSubConsumer
     * @param array $channels
     * @param callable $callback
     * @param bool $isPattern
     * @return mixed
     */
    protected function handleMessage(PubSubConsumer $pubSubConsumer, array $channels, $callback, bool $isPattern = false)
    {
        if($isPattern)
        {
            $pubSubConsumer->psubscribe(...$channels);
        }else
        {
            $pubSubConsumer->subscribe(...$channels);
        }

        /** @var object $message */
        foreach($pubSubConsumer as $message)
        {
            $kind = $message->kind;

            $channel = $message->channel;
            $msg = $message->payload;
            if($kind == 'message')
            {
                return call_user_func($callback, $this->redis, $channel, $msg, $pubSubConsumer);
            }else if($kind == 'pmessage')
            {
                $pattern = $message->pattern ?? '';
                return call_user_func($callback, $this->redis, $pattern, $channel, $msg, $pubSubConsumer);
            }
        }
    }


    /**
     * @return PubSubConsumer|null
     */
    protected function getPubSubConsumer()
    {
        if($this->isCoroutine)
        {
            $pubSubConsumer = $this->redis->pubSubLoop();
            return $pubSubConsumer;
        }else
        {
            if(!$this->pubSubConsumer)
            {
                $this->pubSubConsumer = $this->redis->pubSubLoop();
            }

            return $this->pubSubConsumer;
        }
    }

    /**
     * @param array $channels
     */
    public function unsubscribe(array $channels)
    {
        $pubSubConsumer = $this->redis->pubSubLoop();
        return $pubSubConsumer->unsubscribe(...$channels);
    }

    /**
     * @param array $patterns
     */
    public function punsubscribe(array $patterns)
    {
        $pubSubConsumer = $this->redis->pubSubLoop();
        return $pubSubConsumer->punsubscribe(...$patterns);
    }
}