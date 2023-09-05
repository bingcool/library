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

use Common\Library\Redis\Redis;
use Common\Library\Redis\Predis;
use Common\Library\Redis\RedisConnection;
use Common\Library\Exception\QueueException;

class RedisDelayQueue extends BaseDelayQueue
{
    /**
     * RedisDelayQueue constructor.
     * @param Redis $redis
     * @param string $delayKey
     * @param string|null $option
     * @throws \QueueException
     */
    public function __construct(RedisConnection $redis, string $delayKey, ?string $option = null)
    {
        if ($redis instanceof Predis) {
            throw new QueueException('RedisDelayQueue __construct first argument of redis can not use Common\Library\Redis\Predis');
        }
        parent::__construct($redis, $delayKey, $option);
    }

    /**
     * 从小到大获取成员member,获取后将同时从延迟队列中删除，类似于rpop()队列
     * @param $start
     * @param $end
     * @param array $options
     * @return array
     */
    public function rangeByScore($start, $end, array $options = ['limit' => [0, 9]])
    {
        if (isset($options['limit'])) {
            $offset = $options['limit'][0] ?? null;
            $limit = $options['limit'][1] ?? null;
        }

        $withScores = (int)($options['withscores'] ?? 0);

        $luaScript = LuaScripts::getRangeByScoreLuaScript();

        if ($withScores > 0 && isset($offset) && isset($limit)) {
            $result = $this->redis->eval($luaScript, [$this->delayKey, $start, $end, $offset, $limit, $withScores], 1);
            return $this->mapResult($result);
        } else if (isset($offset) && isset($limit)) {
            return $this->redis->eval($luaScript, [$this->delayKey, $start, $end, $offset, $limit], 1);
        } else if ($withScores > 0) {
            $result = $this->redis->eval($luaScript, [$this->delayKey, $start, $end, '', '', $withScores], 1);
            return $this->mapResult($result);
        } else {
            return $this->redis->eval($luaScript, [$this->delayKey, $start, $end], 1);
        }
    }
}