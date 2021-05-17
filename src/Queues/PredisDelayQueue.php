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

use Common\Library\Cache\Predis;
use Common\Library\Cache\RedisConnection;

class PredisDelayQueue extends BaseDelayQueue
{
    /**
     * @var \Predis\Client
     */
    protected $redis;

    /**
     * PredisDelayQueue constructor.
     * @param Predis $redis
     * @param string $delayKey
     * @param string|null $option
     */
    public function __construct(Predis $redis, string $delayKey, ?string $option = null)
    {
        parent::__construct($redis, $delayKey, $option);
    }

    /**
     * @return RedisConnection|\Predis\Client
     */
    public function getRedis()
    {
        return $this->redis;
    }

    /**
     * @param $start
     * @param $end
     * @param bool|null $withScores
     * @return array
     */
    public function range($start, $end, ?bool $withScores = null)
    {
        if($withScores === true)
        {
            $withScores = ['withscores' => true];
        }else
        {
            $withScores = [];
        }
        return $this->redis->zRange($this->delayKey, $start, $end, $withScores);
    }

    /**
     * 从小到大获取成员member,获取后将同时从延迟队列中删除，类似于rpop()队列
     * @param $start
     * @param $end
     * @param array $options
     * @return array
     */
    public function rangeByScore($start, $end, array $options = ['limit' =>[0,9]])
    {
        if (isset($options['limit']))
        {
            $offset = $options['limit'][0] ?? null;
            $limit = $options['limit'][1] ?? null;
        }

        $withScores = (int)($options['withscores'] ?? 0);

        $luaScript = LuaScripts::getRangeByScoreLuaScript();

        if ($withScores > 0 && isset($offset) && isset($limit)) {
            $result = $this->redis->eval($luaScript, 1, $this->delayKey, ...[$start, $end, $offset, $limit, $withScores]);
            return $this->mapResult($result);
        } else if (isset($offset) && isset($limit)) {
            return $this->redis->eval($luaScript, 1, $this->delayKey, ...[$start, $end, $offset, $limit]);
        } else if ($withScores > 0) {
            $result = $this->redis->eval($luaScript, 1, $this->delayKey, ...[$start, $end, '', '', $withScores]);
            return $this->mapResult($result);
        } else {
            return $this->redis->eval($luaScript, 1, $this->delayKey, ...[$start, $end]);
        }
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

        $this->redis->eval(LuaScripts::getDelayRetryLuaScript(),2, ...[$this->retryMessageKey, $this->delayKey, $member, time() + $delayTime]);
    }

}