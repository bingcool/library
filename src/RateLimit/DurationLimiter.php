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

namespace Swoolefy\Library\RateLimit;

use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Library\Exception\RateLimitException;

/**
 * 滑动窗口限流
 */
class DurationLimiter
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
            throw new RateLimitException("RateKey Missing Setting rateKey");
        }

        if (empty($this->limitNum) || empty($this->windowSizeTime)) {
            throw new RateLimitException("RateLimit Missing Params");
        }

        if ($this->isPredisDriver) {
            $isLimit = $this->redis->eval($this->getLuaLimitScript(), 1, ...[$this->rateKey, $this->windowSizeTime, $this->limitNum]);
        } else {
            $isLimit = $this->redis->eval($this->getLuaLimitScript(), [$this->rateKey, $this->windowSizeTime, $this->limitNum], 1);
        }

        return (bool)$isLimit;
    }

    /**
     * 获取当前窗口内的请求数（通过 Lua 脚本保证时间一致性）
     * @return int
     * @throws RateLimitException
     */
    public function getCurrentCount(): int
    {
        if (empty($this->rateKey)) {
            throw new RateLimitException("RateKey Missing Setting rateKey");
        }

        if (empty($this->windowSizeTime)) {
            throw new RateLimitException("RateLimit Missing Params");
        }

        if ($this->isPredisDriver) {
            $count = $this->redis->eval($this->getLuaCountScript(), 1, ...[$this->rateKey, $this->windowSizeTime]);
        } else {
            $count = $this->redis->eval($this->getLuaCountScript(), [$this->rateKey, $this->windowSizeTime], 1);
        }

        return (int)$count;
    }

    /**
     * @return string
     */
    public function getLuaLimitScript()
    {
        $lua = <<<LUA
local rateKey = KEYS[1];
local windowSizeTime = tonumber(ARGV[1]);
local limitNum = tonumber(ARGV[2]);

local timeStamp = redis.call('TIME');
local second = tonumber(timeStamp[1]);
local microFraction = tonumber(string.sub(timeStamp[2], 1, 4));

-- 计算窗口分数：秒 * 10000 + 微秒前4位，精度为万分之一秒
local windowEndScore = second * 10000 + microFraction;
local windowStartScore = (second - windowSizeTime) * 10000 + microFraction;

-- 删除窗口外的过期数据
redis.call('ZREMRANGEBYSCORE', rateKey, '-inf', windowStartScore);

-- 获取窗口内请求计数
local count = redis.call('ZCARD', rateKey);

-- 判断是否超出限流阈值
if (count < limitNum) then
    -- 使用 当前时间分数:计数 作为唯一 member，避免额外的 Redis 调用
    local uniqueMember = tostring(windowEndScore) .. ':' .. tostring(count);
    redis.call('ZADD', rateKey, windowEndScore, uniqueMember);
    -- 动态 TTL: 窗口大小 * 2，避免固定24小时浪费内存
    redis.call('EXPIRE', rateKey, windowSizeTime * 2);
    return 0;
else
    redis.call('EXPIRE', rateKey, windowSizeTime * 2);
    return 1;
end;

LUA;
        return $lua;
    }

    /**
     * 获取当前窗口请求数的 Lua 脚本
     * @return string
     */
    protected function getLuaCountScript()
    {
        $lua = <<<LUA
local rateKey = KEYS[1];
local windowSizeTime = tonumber(ARGV[1]);

local timeStamp = redis.call('TIME');
local second = tonumber(timeStamp[1]);
local microFraction = tonumber(string.sub(timeStamp[2], 1, 4));

local windowStartScore = (second - windowSizeTime) * 10000 + microFraction;

-- 删除窗口外的过期数据
redis.call('ZREMRANGEBYSCORE', rateKey, '-inf', windowStartScore);

-- 返回窗口内的请求数
return redis.call('ZCARD', rateKey);

LUA;
        return $lua;
    }

    /**
     * @return bool
     */
    public function isPredisDriver()
    {
        if ($this->redis instanceof \Swoolefy\Library\Redis\Predis) {
            $this->isPredisDriver = true;
        }
        return $this->isPredisDriver;
    }

}