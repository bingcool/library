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

use Common\Library\Redis\RedisConnection;
use Common\Library\Redis\Predis;

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
     * 优先队列
     *
     * @var string
     */
    protected $priorityQueueKey;

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
        $this->priorityQueueKey = $queueKey . ':priority_queue';
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function generateMsgId(): string
    {
        return md5(microtime(true). random_bytes(16) . random_int(1,100000));
    }

    /**
     * @param string $queueKey
     * @param array ...$items
     * @return false
     * @throws \Exception
     */
    protected function lpush(string $queueKey, array ...$items)
    {
        $push = [];
        if (empty($items)) {
            return false;
        }
        foreach ($items as $v) {
            if (is_array($v)) {
                $v['__id'] = $this->generateMsgId();
                $v['__retry_num'] = 0;
                $v['__timestamp'] = time();
                $v = json_encode($v);
            }
            $push[] = $v;
        }
        // Predis handle
        if ($this->isPredisDriver) {
            return $this->redis->lPush($queueKey, $push);
        }
        return $this->redis->lPush($queueKey, ...$push);
    }

    /**
     *
     * push data to list
     * @param mixed ...$items
     * @return bool|int
     */
    public function push(array ...$items)
    {
        $this->lpush($this->queueKey, ...$items);
    }

    /**
     * 优先队列
     *
     * @param array ...$items
     * @return false
     * @throws \Exception
     */
    public function pushPriority(array ...$items)
    {
        $this->lpush($this->priorityQueueKey, ...$items);
    }

    /**
     * @param int $timeOut
     * @retur array
     */
    public function pop(int $timeOut = 1)
    {
        if ($timeOut <= 0) {
            $timeOut = 1;
        }

        if ($timeOut > 3) {
            $timeOut = 3;
        }

        if ($this->isPredisDriver) {
            // 优先读取优先队列
            $result = $this->redis->brPop([$this->priorityQueueKey], 1);
            // 优先队列读取不到值
            if ($result === null) {
                $result = $this->redis->brPop([$this->queueKey], $timeOut);
                if ($result === null) {
                    $result = [];
                }
            }
            $this->redis->eval(LuaScripts::getQueueLuaScript(), 2, ...[$this->retryQueueKey, $this->queueKey, '-inf', time(), 0, 100]);
        } else {
            /**
             * @var \Redis $redis
             */
            $redis = $this->redis;
            // 优先读取优先队列
            $result = $redis->brPop($this->priorityQueueKey, 1);
            // 优先队列读取不到值
            if (empty($result)) {
                $result = $redis->brPop($this->queueKey, $timeOut);
            }
            $this->redis->eval(LuaScripts::getQueueLuaScript(), [$this->retryQueueKey, $this->queueKey, '-inf', time(), 0, 100], 2);
        }

        if (isset($result[1]) && is_string($result[1])) {
            $data = json_decode($result[1], true);
            if (!is_null($data)) {
                $result[1] = $data;
            }
        }

        return $result;
    }

    /**
     * @param array $data
     * @param int $delayTime
     * @return void
     */
    public function retry(array $data, int $delayTime = 10)
    {
        if (!isset($data['__id'])) {
            $data['__id'] = $this->generateMsgId();
        }

        // 达到最大的重试次数
        if ($data['__retry_num'] >= $this->retryTimes) {
            return;
        }

        if (!isset($data['__retry_num'])) {
            $data['__retry_num'] = 1;
        }else {
            $retryNum = $data['__retry_num'];
            $data['__retry_num'] = $retryNum + 1;
        }

        $msgId = $data['__id'];

        $data = json_encode($data);

        if ($this->isPredisDriver) {
            $result = $this->redis->eval(LuaScripts::getQueueRetryLuaScript(), 1, ...[$this->retryQueueKey, time() + $delayTime, $data, $this->retryTimes, $msgId]);
        } else {
            $result = $this->redis->eval(LuaScripts::getQueueRetryLuaScript(), [$this->retryQueueKey, time() + $delayTime, $data, $this->retryTimes, $msgId], 1);
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
        if ($retryTimes > 0) {
            $this->retryTimes = $retryTimes;
        }
    }

    /**
     * @return int
     */
    public function getRetryTimes()
    {
        return $this->retryTimes;
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
}