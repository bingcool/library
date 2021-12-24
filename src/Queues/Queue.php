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

namespace Common\Library\Queues;

use Common\Library\Cache\RedisConnection;
use Common\Library\Cache\Predis;

class Queue
{

    /**
     * @var RedisConnection
     */
    protected $redis;

    /**
     * 消费的主队列
     * @var string
     */
    protected $queueKey;

    /**
     * 重试延迟队列（有序集合）
     * @var string
     */
    protected $retryQueueKey;

    /**
     * 记录重试消息hash
     * @var string
     */
    protected $retryMessageKey;

    /**
     * @var integer 重试次数
     */
    protected $retryTimes = 3;

    /**
     * @var bool
     */
    protected $isPredisDriver = false;

    /**
     * Queue constructor.
     * @param RedisConnection $redis
     * @param string $queueKey
     */
    public function __construct(RedisConnection $redis, string $queueKey)
    {
        if ($redis instanceof Predis) {
            $this->isPredisDriver = true;
        }
        $this->redis = $redis;
        $this->queueKey = $queueKey;
        $this->retryQueueKey = $queueKey . ':retry_queue_sort';
        $this->retryMessageKey = $queueKey . ':retry_queue_msg';
    }

    /**
     * push data to list
     * @param mixed ...$items
     * @return bool|int
     */
    public function push(...$items)
    {
        $push = [];
        if (empty($items)) {
            return false;
        }
        foreach ($items as $v) {
            $push[] = is_array($v) ? json_encode($v) : $v;
        }
        // Predis handle
        if ($this->isPredisDriver) {
            return $this->redis->lPush($this->queueKey, $push);
        }
        return $this->redis->lPush($this->queueKey, ...$push);
    }

    /**
     * @param int $timeOut
     * @retur array
     */
    public function pop(int $timeOut)
    {
        if ($timeOut <= 0) {
            $timeOut = 1;
        }

        if ($this->isPredisDriver) {
            $result = $this->redis->brPop([$this->queueKey], $timeOut);
            if ($result === null) {
                $result = [];
            }
            $this->redis->eval(LuaScripts::getQueueLuaScript(), 2, ...[$this->retryQueueKey, $this->queueKey, '-inf', time(), 0, 100]);
        } else {
            /**
             * @var \Redis $redis
             */
            $redis = $this->redis;
            $result = $redis->brPop($this->queueKey, $timeOut);
            $this->redis->eval(LuaScripts::getQueueLuaScript(), [$this->retryQueueKey, $this->queueKey, '-inf', time(), 0, 100], 2);
        }

        return $result;
    }

    /**
     * @param array|string $data
     * @param int $delayTime
     */
    public function retry($data, int $delayTime = 10)
    {
        if (is_array($data)) {
            $data = json_encode($data);
        }

        $uniqueMember = md5($data);

        if ($this->isPredisDriver) {
            $result = $this->redis->eval(LuaScripts::getQueueRetryLuaScript(), 2, ...[$this->retryQueueKey, $this->retryMessageKey, time() + $delayTime, $data, $this->retryTimes, $uniqueMember]);
        } else {
            $result = $this->redis->eval(LuaScripts::getQueueRetryLuaScript(), [$this->retryQueueKey, $this->retryMessageKey, time() + $delayTime, $data, $this->retryTimes, $uniqueMember], 2);
        }
        return $result;
    }

    /**
     * 当前队列长度
     * @return int
     */
    public function count()
    {
        return $this->redis->lLen($this->queueKey);
    }

    /**
     * 设置重置次数
     * @param int $retryTimes
     */
    public function setRetryTimes(int $retryTimes)
    {
        if ($retryTimes <= 0) {
            return;
        }
        $this->retryTimes = $retryTimes;
    }

    /**
     * @return int
     */
    public function getRetryTimes()
    {
        return $this->retryTimes;
    }

    /**
     * 当前重试队列长度
     * @return int
     */
    public function retryQueueCount()
    {
        return (int)$this->redis->zCount($this->retryQueueKey, '-inf', '+inf');
    }

    /**
     * delRetryMessageKey
     */
    public function delRetryMessageKey()
    {
        if ($this->retryQueueCount() == 0 && (int)$this->redis->lLen($this->queueKey) == 0) {
            if ($this->isPredisDriver) {
                $this->redis->del([$this->retryMessageKey]);
            } else {
                /**
                 * @var \Redis $redis
                 */
                $redis = $this->redis;
                $redis->del($this->retryMessageKey);
            }
        }
    }

    /**
     * @return RedisConnection
     */
    public function getRedis()
    {
        return $this->redis;
    }

    /**
     * @return string
     */
    public function getQueueKey()
    {
        return $this->queueKey;
    }

    /**
     * @return string
     */
    public function getRetryQueueKey()
    {
        return $this->retryQueueKey;
    }

    /**
     * @return string
     */
    public function getRetryMessageKey()
    {
        return $this->retryMessageKey;
    }
}