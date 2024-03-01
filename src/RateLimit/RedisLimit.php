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

namespace Common\Library\RateLimit;

use Common\Library\Redis\RedisConnection;
use Common\Library\Exception\RateLimitException;

class RedisLimit
{
    /**
     * rate limit key
     */
    const PREFIX_LIMIT = '_rate_limit:';

    /**
     * @var RedisConnection
     */
    protected $redis;

    /**
     * @var string
     */
    protected $rateKey;

    /** 滑动窗口单位数量,单位个
     * @var int
     */
    protected $limitNum;

    /**
     * 滑动窗口时间，单位秒（不宜设置过大，否资流量分配不均匀）
     * @var int
     */
    protected $windowSizeTime;

    /**
     * @var bool
     */
    protected $isPredisDriver = false;


    /**
     * RedisLimit constructor.
     * @param RedisConnection $redis
     */
    public function __construct(RedisConnection $redis)
    {
        $this->redis = $redis;
        $this->isPredisDriver();
    }

    /**
     * @param string $key
     * @return void
     */
    public function setRateKey(string $key) {
        $this->rateKey = self::PREFIX_LIMIT . $key;
    }

    /**
     * @param int $limitNum
     * @param int $windowSizeTime
     * @return void
     */
    public function setLimitParams(int $limitNum, int $windowSizeTime) {
        $this->limitNum = $limitNum;
        $this->windowSizeTime = $windowSizeTime;
    }

    /**
     * @return bool
     * @throws RateLimitException
     */
    public function isLimit(): bool
    {
        if (empty($this->rateKey)) {
            throw new RateLimitException("RateKey Missing Setting");
        }

        if (empty($this->limitNum) || empty($this->windowSizeTime)) {
            throw new RateLimitException("RateLimit Missing Set Params");
        }

        $requireId = $this->getRequireId();
        $windowEndMilliSecond = (int)$this->getMilliSecond();
        $windowStartMilliSecond = $windowEndMilliSecond - ($this->windowSizeTime * 1000);

        if ($this->isPredisDriver) {
            $isLimit = $this->redis->eval($this->getLuaLimitScript(), 1, ...[$this->rateKey, $windowStartMilliSecond, $windowEndMilliSecond, $this->limitNum, $requireId]);
        } else {
            $isLimit = $this->redis->eval($this->getLuaLimitScript(), [$this->rateKey, $windowStartMilliSecond, $windowEndMilliSecond, $this->limitNum, $requireId], 1);
        }

        return (bool)$isLimit;
    }

    /**
     * @return int
     */
    protected function getRequireId()
    {
        $key = self::PREFIX_LIMIT . 'unique_req_id';
        $redisIncrement = new \Common\Library\Uuid\UuidIncrement($this->redis, $key);
        return $redisIncrement->getIncrId();
    }

    /**
     * @return string
     */
    public function getLuaLimitScript()
    {
        $lua = <<<LUA
local rateKey = KEYS[1];
local windowStartMilliSecond = tonumber(ARGV[1]);
local windowEndMilliSecond = tonumber(ARGV[2]);
local limitNum = tonumber(ARGV[3]);
local requireId = tostring(ARGV[4]);

-- Not EXISTS Key
if redis.call('EXISTS', rateKey) == 0 then
    redis.call('EXPIRE', rateKey, 24 * 3600)
end

-- delete data
redis.call('zRemRangeByScore', rateKey, '-inf', windowStartMilliSecond);

-- get in limit time count
local count = redis.call('zCount', rateKey, windowStartMilliSecond, windowEndMilliSecond);

-- can access rate limit
if (count < limitNum) then
    redis.call('zAdd', rateKey, windowEndMilliSecond, requireId);
    return 0;
else 
    return 1;
end;

LUA;
        return $lua;

    }

    /**
     * @return int
     */
    protected function getMilliSecond()
    {
        $time = explode(" ", microtime());

        $time = $time[1] . ($time[0] * 1000);

        $time2 = explode(".", $time);

        $time = $time2[0];

        return $time;
    }

    /**
     * @return bool
     */
    public function isPredisDriver()
    {
        if ($this->redis instanceof \Common\Library\Redis\Predis) {
            $this->isPredisDriver = true;
        }
        return $this->isPredisDriver;
    }

}