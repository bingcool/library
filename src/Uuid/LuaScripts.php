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

namespace Common\Library\Uuid;

class LuaScripts
{
    /**
     * @return string
     */
    public static function getUuidLuaScript()
    {
        $lua = <<<LUA
local incrKey = KEYS[1];
local step = ARGV[1];
local expireTime = ARGV[2];

-- default value
if step == nil then
    step = 1;
end;

-- default value
if expireTime == nil then
    expireTime = 20;
end;

step = tonumber(step);
expireTime = tonumber(expireTime);

local incrId = redis.call('IncrBy', incrKey, step);
local timeStamp = {};
local preTimeStampKey = table.concat({incrKey,'time_stamp'},'_');

-- exceed
if incrId > 999990 then
    redis.call('Del', incrKey);
    incrId = redis.call('IncrBy', incrKey, step);
end;

local timeStamp = redis.call('TIME');
local second = timeStamp[1];
local msecond = string.sub(timeStamp[2], 1, 3);
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

return {table.concat({second, msecond, '000000'}), incrId};

LUA;
        return $lua;
    }
}