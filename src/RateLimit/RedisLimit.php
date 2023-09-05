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
    const PREFIX_LIMIT = 'rate_limit:';

    /**
     * @var RedisConnection
     */
    protected $redis;

    /**
     * @var string
     */
    protected $rateKey;

    /** 滑动窗口单位数量,单位秒
     * @var int
     */
    protected $limitNum;

    /**
     * 滑动窗口时间，小时|分钟|秒 ,单位秒
     * @var int
     */
    protected $limitTime;

    /**
     * sort set 保留请求id数据最长时长,单位秒
     * 有时需要统计可能是小时级别，也可能是分钟级别，秒级别
     * 如果有小时级别流量控制，那么设置为24小时(86400s)即可
     * 如果最大级别只有分钟流量控制，那么设置为1小时(3600s)即可
     * 如果只有秒级流量控制，那么设置为1分钟(60s)即可
     * @var int
     */
    protected $ttl;

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
     * @param int $time
     * @param int $num
     * @param int $ttl
     * @return void
     */
    public function setLimitParams(int $limitNum, int $limitTime, int $ttl) {
        $this->limitNum = $limitNum;
        $this->limitTime = $limitTime;
        $this->ttl = $ttl;
    }

    /**
     * @param string $key
     * @param int $limitTime
     * @param int $limitNum
     * @param int $ttl
     * @return bool
     */
    public function isLimit(): bool
    {
        if (empty($this->rateKey)) {
            throw new RateLimitException("RateKey Missing Setting");
        }

        if (empty($this->limitNum) || empty($this->limitTime) || empty($this->ttl)) {
            throw new RateLimitException("RateLimit Missing Set Params");
        }

        $requireId = $this->getRequireId();
        $endMilliSecond = $this->getMilliSecond();
        $startMilliSecond = $endMilliSecond - ($this->limitTime * 1000);
        $remRemainTime = $endMilliSecond - ($this->ttl * 1000);

        if ($this->isPredisDriver) {
            $isLimit = $this->redis->eval($this->getLuaLimitScript(), 1, ...[$this->rateKey, $startMilliSecond, $endMilliSecond, $remRemainTime, $this->limitNum, $requireId]);
        } else {
            $isLimit = $this->redis->eval($this->getLuaLimitScript(), [$this->rateKey, $startMilliSecond, $endMilliSecond, $remRemainTime, $this->limitNum, $requireId], 1);
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
local startMilliSecond = ARGV[1];
local endMilliSecond = ARGV[2];
local remRemainTime = tonumber(ARGV[3]);
local limitNum = tonumber(ARGV[4]);
local requireId = tonumber(ARGV[5]);

-- get in limit time count
local count = redis.call('zCount', rateKey, startMilliSecond, endMilliSecond);
-- can access rate limit
if (count < limitNum) then
    -- delete data
    redis.call('zRemRangeByScore', rateKey, '-inf', remRemainTime);
    redis.call('zAdd', rateKey, endMilliSecond, requireId);
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