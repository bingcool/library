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

/**
 * 令牌桶限流器
 *
 * 以固定速率向桶中填充令牌，请求时消耗令牌。
 * 桶满时多余令牌丢弃，适合允许突发流量(burst)的场景。
 *
 * 使用 Redis Hash 存储桶状态，Lua 脚本保证原子性。
 *
 * Usage:
 *   $limiter = new TokenBucketLimiter($redis);
 *   $limiter->setRateKey('api:user:123');
 *   $limiter->setLimitParams(100, 10.0); // 桶容量100, 每秒填充10个令牌
 *   if ($limiter->isLimit()) {
 *       // 被限流
 *   }
 */
class TokenBucketLimiter
{
    /**
     * rate limit key prefix
     */
    const PREFIX_LIMIT = '_rate_limit:token_bucket:';

    /**
     * @var RedisConnection
     */
    protected $redis;

    /**
     * @var string
     */
    protected $rateKey;

    /**
     * 桶容量（最大令牌数）
     * @var int
     */
    protected $capacity;

    /**
     * 令牌填充速率（个/秒）
     * @var float
     */
    protected $rate;

    /**
     * @var bool
     */
    protected $isPredisDriver = false;

    /**
     * TokenBucketLimiter constructor.
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
    public function setRateKey(string $key)
    {
        $this->rateKey = self::PREFIX_LIMIT . $key;
    }

    /**
     * @param int   $capacity 桶容量（最大令牌数）
     * @param float $rate     令牌填充速率（个/秒），必须大于0
     * @return void
     */
    public function setLimitParams(int $capacity, float $rate)
    {
        $this->capacity = $capacity;
        $this->rate = $rate;
    }

    /**
     * 尝试获取令牌，判断是否被限流
     *
     * @param int $tokens 本次请求需要消耗的令牌数
     * @return bool true=被限流 false=允许通过
     * @throws RateLimitException
     */
    public function isLimit(int $tokens = 1): bool
    {
        if (empty($this->rateKey)) {
            throw new RateLimitException("RateKey Missing Setting rateKey");
        }

        if (empty($this->capacity) || empty($this->rate)) {
            throw new RateLimitException("RateLimit Missing Params");
        }

        if ($tokens < 1) {
            throw new RateLimitException("RateLimit tokens must be at least 1");
        }

        if ($this->isPredisDriver) {
            $isLimit = $this->redis->eval(
                $this->getLuaLimitScript(),
                1,
                ...[$this->rateKey, $this->capacity, $this->rate, $tokens]
            );
        } else {
            $isLimit = $this->redis->eval(
                $this->getLuaLimitScript(),
                [$this->rateKey, $this->capacity, $this->rate, $tokens],
                1
            );
        }

        return (bool)$isLimit;
    }

    /**
     * 获取当前桶内令牌数（近似值，查询与实际使用之间可能有变化）
     *
     * @return float
     * @throws RateLimitException
     */
    public function getCurrentTokens(): float
    {
        if (empty($this->rateKey)) {
            throw new RateLimitException("RateKey Missing Setting rateKey");
        }

        if (empty($this->capacity) || empty($this->rate)) {
            throw new RateLimitException("RateLimit Missing Params");
        }

        if ($this->isPredisDriver) {
            $result = $this->redis->eval(
                $this->getLuaTokensScript(),
                1,
                ...[$this->rateKey, $this->capacity, $this->rate]
            );
        } else {
            $result = $this->redis->eval(
                $this->getLuaTokensScript(),
                [$this->rateKey, $this->capacity, $this->rate],
                1
            );
        }

        return round((float)$result, 4);
    }

    /**
     * 令牌桶限流 Lua 脚本
     *
     * KEYS[1] = rateKey
     * ARGV[1] = capacity   桶容量
     * ARGV[2] = rate       填充速率(个/秒)
     * ARGV[3] = tokens     本次请求消耗令牌数
     *
     * @return string
     */
    public function getLuaLimitScript()
    {
        $lua = <<<'LUA'
local rateKey = KEYS[1];
local capacity = tonumber(ARGV[1]);
local rate = tonumber(ARGV[2]);
local requested = tonumber(ARGV[3]);

-- 使用 Redis 服务器时间，保证分布式一致性
local timeStamp = redis.call('TIME');
local now = tonumber(timeStamp[1]) + tonumber(timeStamp[2]) / 1000000;

-- 读取桶的当前状态
local bucketData = redis.call('HMGET', rateKey, 'tokens', 'timestamp');
local currentTokens = tonumber(bucketData[1]);
local lastTime = tonumber(bucketData[2]);

-- 首次访问，初始化为满桶
if currentTokens == nil then
    currentTokens = capacity;
    lastTime = now;
end

-- 计算经过的时间并填充令牌
local elapsed = math.max(0, now - lastTime);
local newTokens = math.min(capacity, currentTokens + elapsed * rate);

-- 判断令牌是否足够
if newTokens >= requested then
    -- 扣减令牌，更新状态
    newTokens = newTokens - requested;
    redis.call('HMSET', rateKey, 'tokens', tostring(newTokens), 'timestamp', tostring(now));
    -- 动态 TTL：桶从空到满的时间 * 2，确保空闲时 key 能被回收
    local ttl = math.ceil(capacity / rate) * 2;
    if ttl < 60 then
        ttl = 60;
    end
    redis.call('EXPIRE', rateKey, ttl);
    return 0;
else
    -- 令牌不足，仍然更新填充状态（不扣减）
    redis.call('HMSET', rateKey, 'tokens', tostring(newTokens), 'timestamp', tostring(now));
    local ttl = math.ceil(capacity / rate) * 2;
    if ttl < 60 then
        ttl = 60;
    end
    redis.call('EXPIRE', rateKey, ttl);
    return 1;
end
LUA;
        return $lua;
    }

    /**
     * 查询当前令牌数的 Lua 脚本（只读，不扣减）
     *
     * KEYS[1] = rateKey
     * ARGV[1] = capacity
     * ARGV[2] = rate
     *
     * @return string
     */
    protected function getLuaTokensScript()
    {
        $lua = <<<'LUA'
local rateKey = KEYS[1];
local capacity = tonumber(ARGV[1]);
local rate = tonumber(ARGV[2]);

local timeStamp = redis.call('TIME');
local now = tonumber(timeStamp[1]) + tonumber(timeStamp[2]) / 1000000;

local bucketData = redis.call('HMGET', rateKey, 'tokens', 'timestamp');
local currentTokens = tonumber(bucketData[1]);
local lastTime = tonumber(bucketData[2]);

-- 桶不存在，返回满桶容量
if currentTokens == nil then
    return tostring(capacity);
end

-- 计算填充后的令牌数
local elapsed = math.max(0, now - lastTime);
local newTokens = math.min(capacity, currentTokens + elapsed * rate);

return tostring(newTokens);
LUA;
        return $lua;
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
