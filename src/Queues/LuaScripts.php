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

class LuaScripts
{
    /**
     * @return string
     */
    public static function getDelayRetryLuaScript()
    {
        $lua = <<<LUA
local delayKey = KEYS[1];
local msgId = ARGV[1];
local member = ARGV[2];
local scoreTime = ARGV[3];
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

local members = {};
local tempMembers = {};

if ( (type(tonumber(limit)) == 'number' ) and ( tonumber(withScores) == 1 ) ) then
    members = redis.call('zRangeByScore', delayKey, startScore, endScore,'withscores','limit', offset, limit);
    local j = 1;
    for i,member in ipairs(members) do
        if type(tonumber(member)) ~= 'number' then
            tempMembers[j] = member
            j = j + 1;
        end
    end
    
    -- delete data
    if #tempMembers > 0 then
        redis.call("ZREM", delayKey, unpack(tempMembers))
    end
    
    members = tempMembers;
    
elseif type(tonumber(limit)) == 'number' then
    members = redis.call('zRangeByScore', delayKey, startScore, endScore, 'limit', offset, limit);
    -- delete data
    if #members > 0 then 
        redis.call("ZREM", delayKey, unpack(members))
    end
elseif ( tonumber(withScores) == 1 ) then
    members = redis.call('zRangeByScore', delayKey, startScore, endScore, 'withscores');
    
    local j = 1;
    for i,member in ipairs(members) do
        if type(tonumber(member)) ~= 'number' then
            tempMembers[j] = member
            j = j + 1;
        end
    end
    
    -- delete data
    redis.call('zRemRangeByScore', delayKey, startScore, endScore);
    
    members = tempMembers;
    
else
    members = redis.call('zRangeByScore', delayKey, startScore, endScore);
    -- delete data
    redis.call('zRemRangeByScore', delayKey, startScore, endScore);
end;

return members;

    
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

local members = {};

-- get retry item
if type(tonumber(limit)) == 'number' then
    members = redis.call('zRangeByScore', delayRetryKey, startScore, endScore, 'limit', offset, limit);
else
    members = redis.call('zRangeByScore', delayRetryKey, startScore, endScore);
end;

-- lPush queue
for k,v in ipairs(members) do
    redis.call('lPush', queueKey, v);
end;

-- delete data
if #members > 0 then
    redis.call('ZREM', delayRetryKey, unpack(members));
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

-- retryQueueKey need use data
local nextTime = ARGV[1];
local targetMember = ARGV[2];

-- retryMessageKey need use data 
local retryTimes = ARGV[3];
local msgId = ARGV[4];

return redis.call('zAdd', retryQueueKey, nextTime, targetMember);

LUA;
        return $lua;
    }
}
