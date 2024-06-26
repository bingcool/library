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
    const OPTIONS = ['NX', 'XX', 'CH', 'INCR'];

    /**
     * RedisDelayQueue constructor.
     * @param RedisConnection $redis
     * @param string $delayKey
     * @throws QueueException
     */
    public function __construct(RedisConnection $redis, string $delayKey, ?string $option = null)
    {
        if ($option && !in_array(strtoupper($option), static::OPTIONS)) {
            throw new QueueException('Redis Sort Score Number Option Error');
        }
        $this->redis = $redis;
        $this->delayKey = $delayKey;
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
        if ($retryTimes <= 0) {
            return;
        }
        $this->retryTimes = $retryTimes;
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
     * 由于是延迟队列，一般score存入当前时间戳，$delayTime为延迟时间，单位秒
     * @param array $memberValue
     * @param int $delayTime
     * @return $this
     */
    public function addItem(array $memberValue, int $delayTime)
    {
        $score = time();
        $this->sortData[] = $score + $delayTime;
        // 有序集合不能重复元素
        $memberValue['__id'] = $this->generateMsgId();
        $memberValue['__retry_num'] = 0;
        $memberValue['__timestamp'] = $score;
        $this->sortData[] = is_array($memberValue) ? json_encode($memberValue) : $memberValue;

        if (count($this->sortData) >= 200) {
            $this->push();
        }

        return $this;
    }

    /**
     * @return int
     */
    public function push()
    {
        if (empty($this->sortData)) {
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
        $chunks = array_chunk($result, 2, false);
        $data = [];
        foreach ($chunks as $chunk) {
            $data[] = $chunk[0];
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
     * @param $start
     * @param $end
     * @param array $options
     * @return array
     */
    public function rangeByScore($start, $end, array $options = ['limit' => [0, 9]])
    {
        // TODO: Implement rangeByScore() method.
    }

    /**
     * @param array $options
     * @return array
     */
    public function pop(array $options =  ['limit' => [0, 9]] )
    {
        $result = $this->rangeByScore('-inf', time(), $options);
        foreach ($result as &$item) {
            if (is_string($item)) {
                $item = json_decode($item, true) ?? $item;
            }
        }
        return $result;
    }

    /**
     * 重试
     * @param array $member
     * @param int $delayTime
     * @return mixed
     */
    public function retry(array $member, int $delayTime)
    {
        list($member, $overRetryNum) = $this->parseMaxRetryNum($member);
        // 达到最大的重试次数
        if ($overRetryNum) {
            return;
        }
        $msgId = $member['__id'];

        $member = json_encode($member);
        $this->redis->eval(LuaScripts::getDelayRetryLuaScript(), [$this->delayKey, $msgId, $member, (time() + $delayTime)], 1);
    }

    /**
     * @param array $member
     * @return array
     * @throws \RedisException
     */
    protected function parseMaxRetryNum(array $member): array
    {
        if (!isset($member['__id'])) {
            $msgId = $this->generateMsgId();
            $member['__id'] = $msgId;
        }

        // 达到最大重试次数
        if ($member['__retry_num'] >= $this->retryTimes) {
            $overRetryNum = true;
        }

        if (!isset($member['__retry_num'])) {
            $member['__retry_num'] = 1;
        }else {
            $retryNum = $member['__retry_num'];
            $member['__retry_num'] = $retryNum + 1;
        }

        return [$member, $overRetryNum ?? false];
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        $this->push();
    }
}