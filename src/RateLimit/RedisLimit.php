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

namespace Common\Library\RateLimit;

use Common\Library\Exception\UuidException;

class RedisLimit
{
    /**
     *
     */
    const PREFIX_LIMIT = 'rate_limit:';

    /**
     * @var \Predis\Client|\Redis
     */
    protected $redis;

    /**
     * @var string
     */
    protected $rateKey;

    /**
     * 滑动窗口时间，小时|分钟|秒级别
     * @var integer
     */
    protected $limitTime;

    /**
     * @var integer
     */
    protected $limitNum;

    /**
     * sort set 保留请求id数据最长时长
     * 有时需要统计可能是小时级别，也可能是分钟级别，秒级别
     * 如果有小时级别流量控制，那么设置为24小时(86400s)即可
     * 如果最大级别只有分钟流量控制，那么设置为1小时(3600s)即可
     * 如果只有秒级流量控制，那么设置为1分钟(60s)即可
     * @var integer
     */
    protected $remainTime;

    /**
     * @var bool
     */
    protected $isPredisDriver = false;


    /**
     * RedisLimit constructor.
     */
    public function __construct($redis)
    {
        $this->redis = $redis;
        $this->isPredisDriver($isPredisDriver ?? null);
    }

    /**
     * @param string $key
     * @param int $limitTime
     * @param int $limitNum
     * @param int $remainTime
     *
     */
    public function checkLimit(
        string $key,
        int $limitTime,
        int $limitNum,
        int $remainTime = 3600
    )
    {
        $this->rateKey = self::PREFIX_LIMIT.$key;
        $this->limitTime = $limitTime;
        $this->limitNum = $limitNum;
        $this->remainTime = $remainTime;

        $requireId = $this->getRequireId();
        $endMilliSecond = $this->getMilliSecond();
        $startMilliSecond = $endMilliSecond - ($this->limitTime * 1000);
        $remRemainTime = $endMilliSecond - ($this->remainTime * 1000);

        if($this->isPredisDriver)
        {
            $isAccess = $this->redis->eval($this->getLuaLimitScript(), 1, ...[$this->rateKey, $startMilliSecond, $endMilliSecond, $remRemainTime, $limitNum, $requireId]);
        }else
        {
            $isAccess = $this->redis->eval($this->getLuaLimitScript(),[$this->rateKey, $startMilliSecond, $endMilliSecond, $remRemainTime, $limitNum, $requireId],1);
        }

        return $isAccess ?? true;
    }

    /**
     * @return integer
     */
    protected function getRequireId()
    {
        $key = $this->rateKey.':requireId';
        $redisIncrement = new \Common\Library\Uuid\RedisIncrement($this->redis,$key, $this->isPredisDriver);
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
local remRemainTime = ARGV[3];
local limitNum = ARGV[4];
local requireId = ARGV[5];

-- get in limit time count
count = redis.call('zCount', rateKey, startMilliSecond, endMilliSecond);
-- can access rate limit
if (count < limitNum) then
    -- delete data
    redis.call('zRemRangeByScore', rateKey, '-inf', remRemainTime);
    redis.call('zAdd', rateKey, endMilliSecond, requireId);
    return true;
else 
    return false;
end;

return true;

LUA;
        return $lua;

    }


    protected function getMilliSecond()
    {
        $time = explode(" ", microtime());

        $time = $time[1] . ($time[0] * 1000);

        $time2 = explode(".", $time);

        $time = $time2[0];

        return $time;
    }

    /**
     * @param bool|null $isPredisDriver
     * @throws UuidException
     */
    public function isPredisDriver(?bool $isPredisDriver = null)
    {
        if(!is_object($this->redis))
        {
            throw new UuidException(sprintf('%s::%s params of redis must be a object',__CLASS__,__FUNCTION__));
        }

        if(is_null($isPredisDriver))
        {
            if((class_exists('Predis\\Client') && $this->redis instanceof \Predis\Client)
                || (class_exists('Common\Library\Cache\Predis') && $this->redis instanceof \Common\Library\Cache\Predis))
            {
                $this->isPredisDriver = true;
            }
        }else
        {
            $this->isPredisDriver = $isPredisDriver;
        }
    }


}