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

namespace Common\Library\Queue;

use Common\Library\Cache\RedisConnection;
use Common\Library\Exception\QueueException;
use \Common\Library\Queue\Interfaces\AbstractDelayQueueInterface;

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
     * @throws \QueueException
     */
    public function __construct(RedisConnection $redis, string $delayKey, ?string $option = null)
    {
        $this->redis = $redis;
        $this->delayKey = $delayKey;
        if($option && !in_array(strtoupper($option), static::OPTIONS))
        {
            throw new QueueException('Redis Sort Score Number Option Error');
        }

        $this->option = $option;
    }

    /**
     * @param $option
     */
    public function setOption($option)
    {
        $this->option = $option;
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
     * @param string $key
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
     * @return int
     */
    public function rank($member)
    {
        return $this->redis->zRank($this->delayKey, $member);
    }

    /**
     * 针对返回withScores的数据，取出数据保持与直接调用$redis->zRangeByScore('key', 0, 3, array('withscores' => TRUE);保持一致
     * @param $result
     * @return array
     */
    protected function mapResult($result)
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
     * @return RedisConnection|\Redis
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
     */
    public function rangeByScore($start, $end, array $options = ['limit' => [0, 9]])
    {
        // TODO: Implement rangeByScore() method.
    }

    /**
     * @return string
     */
    public static function getRangeByScoreLuaScript()
    {
        $lua = <<<LUA
local delayKey = KEYS[1];
local startScore = ARGV[1];
local endScore = ARGV[2];
local offset =  ARGV[3];
local limit = ARGV[4];
local withScores = ARGV[5];

local ret = {};

if ( (type(tonumber(limit)) == 'number' ) and ( tonumber(withScores) == 1 ) ) then
    ret = redis.call('zRangeByScore', delayKey, startScore, endScore,'withscores','limit', offset, limit);
elseif type(tonumber(limit)) == 'number' then
    ret = redis.call('zRangeByScore', delayKey, startScore, endScore, 'limit', offset, limit);
elseif ( tonumber(withScores) == 1 ) then
    ret = redis.call('zRangeByScore', delayKey, startScore, endScore, 'withscores');
else
    ret = redis.call('zRangeByScore', delayKey, startScore, endScore);
end;

-- delete data
    redis.call('zRemRangeByScore', delayKey, startScore, endScore);
    
    return ret;
    
LUA;
        return $lua;
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        $this->push();
    }

}