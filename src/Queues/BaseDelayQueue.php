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

namespace Common\Library\Queues;

use Common\Library\Cache\RedisConnection;
use Common\Library\Exception\QueueException;
use Common\Library\Queues\Interfaces\AbstractDelayQueueInterface;

class BaseDelayQueue extends AbstractDelayQueueInterface
{
    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * @var string
     */
    protected $delayKey;

    /**
     * 记录重试信息
     * @var string
     */
    protected $retryMessageKey;

    /**
     * 重试次数
     * @var int
     */
    protected $retryTimes = 3;

    /**
     * @var array
     */
    protected $sortData = [];

    /**
     * @var null
     */
    protected $option = null;

    /**
     * @var array
     */
    const OPTIONS = ['NX','XX','CH','INCR'];

    /**
     * RedisDelayQueue constructor.
     * @param RedisConnection $redis
     * @param string $delayKey
     * @throws QueueException
     */
    public function __construct(RedisConnection $redis, string $delayKey, ?string $option = null)
    {
        if($option && !in_array(strtoupper($option), static::OPTIONS))
        {
            throw new QueueException('Redis Sort Score Number Option Error');
        }
        $this->redis = $redis;
        $this->delayKey = $delayKey;
        $this->retryMessageKey = $delayKey.':retry_delq_msg';
        $this->option = $option;
    }

    /**
     * @param $option
     * @return void
     */
    public function setOption($option)
    {
        $this->option = $option;
    }

    /**
     * 重试次数
     * @param int $retryTimes
     * @return void
     */
    public function setRetryTimes(int $retryTimes)
    {
        if($retryTimes <= 0)
        {
            return;
        }
        $this->retryTimes = $retryTimes;
    }

    /**
     * 由于是延迟队列，一般score存入当前时间戳，$delayTime为延迟时间，单位秒
     * @param int $score
     * @param $member
     * @param int $delayTime
     * @return $this
     */
    public function addItem(int $score, $member, int $delayTime)
    {
        if($score < 0)
        {
            $score = time();
        }
        $this->sortData[] = $score + $delayTime;
        $this->sortData[] = is_array($member) ? json_encode($member) : $member;

        if(count($this->sortData) >= 200)
        {
            $this->push();
        }

        return $this;
    }

    /**
     * @return int
     */
    public function push()
    {
        if(empty($this->sortData))
        {
            return 0;
        }

        $number = $this->redis->zadd($this->delayKey, ...$this->sortData);

        $this->sortData = [];

        return $number;
    }

    /**
     * @param $start
     * @param $end
     * @return int
     */
    public function count($start, $end)
    {
        return $this->redis->zCount($this->delayKey, $start, $end);
    }

    /**
     * @param $increment
     * @param $member
     * @return float
     */
    public function incrBy($increment, $member)
    {
        return $this->redis->zIncrBy($this->delayKey, $increment, $member);
    }

    /**
     * @param array $memberArr
     * @return int
     */
    public function rem(array $memberArr)
    {
        return $this->redis->zRem($this->delayKey, ...$memberArr);
    }

    /**
     * @param $start
     * @param $end
     * @return int
     */
    public function remRangeByScore($start, $end)
    {
        return $this->redis->zRemRangeByScore($this->delayKey, $start, $end);
    }

    /**
     * @param $start
     * @param $end
     * @param bool|null $withScores
     * @return array
     */
    public function range($start, $end, ?bool $withScores = null)
    {
        return $this->redis->zRange($this->delayKey, $start, $end, $withScores);
    }

    /**
     * @param $member
     * @return int|bool
     */
    public function rank($member)
    {
        return $this->redis->zRank($this->delayKey, $member);
    }

    /**
     * 针对返回withScores的数据，取出数据保持与直接调用$redis->zRangeByScore('key', 0, 3, array('withscores' => TRUE);保持一致
     * @param array $result
     * @return array
     */
    protected function mapResult(array $result)
    {
        $chuncks = array_chunk($result, 2, false);
        $data = [];
        foreach($chuncks as $chunck)
        {
            if(preg_match("/^[1-9][0-9]*$/", $chunck[1]))
            {
                $data[$chunck[0]] = (int)$chunck[1];
            }else
            {
                $data[$chunck[0]] = $chunck[1];
            }
        }
        return $data;
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
    public function getDelayKey()
    {
        return $this->delayKey;
    }

    /**
     * @return string
     */
    public function getRetryMessageKey()
    {
        return $this->retryMessageKey;
    }

    /**
     * 获取目前队列重试的成员数量
     * @return int
     */
    public function getRetryNumbers()
    {
        return $this->redis->hLen($this->getRetryMessageKey());
    }

    /**
     * @param $start
     * @param $end
     * @param array $options
     */
    public function rangeByScore($start, $end, array $options = ['limit' => [0, 9]])
    {
        // TODO: Implement rangeByScore() method.
    }

    /**
     * 重试
     * @param $member
     * @param int $delayTime
     * @return mixed
     */
    public function retry($member, int $delayTime)
    {
        $retryTimes = $this->redis->hGet($this->retryMessageKey, $member);
        if($retryTimes >= $this->retryTimes)
        {
            $this->redis->hDel($this->retryMessageKey, $member);
            return;
        }

        $this->redis->eval(LuaScripts::getDelayRetryLuaScript(), [$this->retryMessageKey, $this->delayKey, $member, (time() + $delayTime)], 2);
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        $this->push();
    }

}