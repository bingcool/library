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

class LuaScripts
{
    /**
     * @return string
     */
    public static function getDelayRetryLuaScript()
    {
        $lua = <<<LUA
local retryMessageKey = KEYS[1];
local delayKey = KEYS[2];
local member = ARGV[1];
local scoreTime = ARGV[2];

redis.call('hIncrBy', retryMessageKey, member , 1);

redis.call('zAdd', delayKey, scoreTime, member);

LUA;
        return $lua;
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
     * @return string
     */
    public static function getQueueLuaScript()
    {
        $lua = <<<LUA
local delayRetryKey = KEYS[1];
local queueKey = KEYS[2];
local startScore = ARGV[1];
local endScore = ARGV[2];
local offset =  ARGV[3];
local limit = ARGV[4];

local ret = {};

-- get retry item
if type(tonumber(limit)) == 'number' then
    ret = redis.call('zRangeByScore', delayRetryKey, startScore, endScore, 'limit', offset, limit);
else
    ret = redis.call('zRangeByScore', delayRetryKey, startScore, endScore);
end;

-- lPush queue
for k,v in ipairs(ret) do
    redis.call('lPush', queueKey, v);
end;

-- delete data
if next(ret) ~= nil then
    redis.call('zRemRangeByScore', delayRetryKey, startScore, endScore);
end;

return true;

LUA;
        return $lua;
    }

    /**
     * return string
     */
    public static function getQueueRetryLuaScript()
    {
        $lua = <<<LUA
local retryQueueKey = KEYS[1];
local retryMessageKey = KEYS[2];

-- retryQueueKey need use data
local nextTime = ARGV[1];
local targetMember = ARGV[2];

-- retryMessageKey need use data 
local retryTimes = ARGV[3];
local uniqueMember = ARGV[4];

local hasRetryTimes = redis.call('hGet', retryMessageKey, uniqueMember);

if hasRetryTimes then
    if ( hasRetryTimes >= retryTimes ) then
        redis.call('hDel', retryMessageKey, uniqueMember);
        return 1;
    end;
end;

-- retry member incr retryTimes
redis.call('hIncrBy', retryMessageKey, uniqueMember , 1);

return redis.call('zAdd', retryQueueKey, nextTime, targetMember);

LUA;
        return $lua;
    }
}
