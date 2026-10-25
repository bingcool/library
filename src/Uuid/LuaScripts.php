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
declare(strict_types=1);

namespace Swoolefy\Library\Uuid;

class LuaScripts
{
    /**
     * 同一毫秒内序号上限。超过后滚动 incrKey，避免 incr 进位到下一毫秒。
     */
    public const MAX_INCR = 999990;

    /**
     * 申请步长钳到 [1, MAX_INCR]。PHP 展开区间必须用这个值，不能用调用方原始数量。
     */
    public static function clampStep(int $count): int
    {
        if ($count > self::MAX_INCR) {
            return self::MAX_INCR;
        }
        if ($count < 1) {
            return 1;
        }

        return $count;
    }

    /**
     * generateId 返回的是本段右端（prefix + incrId）。
     * 合法区间是 (maxId - count, maxId]，即 [maxId - count + 1, maxId]。
     * 用 maxId - count 会包含上一号、丢掉本段最大号。
     *
     * @return int[]
     */
    public static function expandRange(int $maxId, int $count): array
    {
        $count = self::clampStep($count);
        $minId = $maxId - $count + 1;
        if ($minId <= 0) {
            return [];
        }

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $minId + $i;
        }

        return $ids;
    }

    /**
     * @return string
     */
    public static function getUuidLuaScript()
    {
        $maxIncr = self::MAX_INCR;
        $lua = <<<LUA
local incrKey = KEYS[1];
local step = ARGV[1];
local expireTime = ARGV[2];

-- default value
if step == nil then
    step = 1;
end;

-- default value。0 或负数会让 EXPIRE 立刻删 key
if expireTime == nil then
    expireTime = 20;
end;

step = tonumber(step);
expireTime = tonumber(expireTime);
if expireTime == nil or expireTime < 1 then
    expireTime = 20;
end;

if step == nil or step < 1 then
    step = 1;
end;
if step > {$maxIncr} then
    step = {$maxIncr};
end;

local incrId = redis.call('IncrBy', incrKey, step);
local timeStamp = {};
local preTimeStampKey = table.concat({incrKey,'time_stamp'},'_');

-- exceed：回绕后若 step 仍大于上限（异常写入），拒绝本段，避免序号进位到下一毫秒
if incrId > {$maxIncr} then
    redis.call('Del', incrKey);
    incrId = redis.call('IncrBy', incrKey, step);
    if incrId > {$maxIncr} then
        redis.call('Del', incrKey);
        return {};
    end;
end;

local timeStamp = redis.call('TIME');
local second = timeStamp[1];
-- TIME 的微秒不补零，"51234" 直接 sub 会把 51ms 编成 512
local micro = string.format('%06d', tonumber(timeStamp[2]));
local msecond = string.sub(micro, 1, 3);
local nowTimeValue = table.concat({second, msecond});
local preTimeValue;

-- first set incrKey
if incrId == step then
    redis.call('expire', incrKey, expireTime);
    preTimeValue = redis.call('Get', preTimeStampKey);
    
    if preTimeValue == nowTimeValue then
         return {};
    end;
end;

redis.call('Set', preTimeStampKey, nowTimeValue);
-- SET 会丢掉原 TTL。时间戳必须不短于 incrKey，计数过期重计时仍能挡住同一毫秒回拨
redis.call('Expire', preTimeStampKey, expireTime);

return {table.concat({second, msecond, '000000'}), incrId};

LUA;
        return $lua;
    }
}