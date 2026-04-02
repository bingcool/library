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

namespace Common\Library\Cache\Driver;

use Common\Library\Redis\Predis;
use Common\Library\Cache\CacheInterface;
use Common\Library\Redis\RedisConnection;

class RedisCache implements CacheInterface
{
    /**
     * @var RedisConnection
     */
    protected $driver;
    /**
     * @var bool
     */
    protected $isPredisDriver = false;

    /**
     * @param RedisConnection $redis
     */
    public function __construct(RedisConnection $redis)
    {
        if ($redis instanceof Predis) {
            $this->isPredisDriver = true;
        }
        $this->driver = $redis;
    }

    /**
     * @param string $key
     * @param $value
     * @param int|null $ttl 缓存时间-单位秒
     * @return mixed
     */
    public function set(string $key, $value, ?int $ttl = null)
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (!is_null($ttl) && $ttl > 0) {
            $result = $this->driver->setex($key, $ttl, $value);
        } else {
            $result = $this->driver->set($key, $value);
        }

        return $result;
    }

    /**
     * @param string $key
     * @param $default
     * @return mixed
     */
    public function get(string $key)
    {
        $result = $this->driver->get($key);
        if (is_string($result)) {
            if (\PHP_VERSION_ID >= 80300 && json_validate($result)) {
                $result = json_decode($result, true);
                if (is_null($result)) {
                    throw new \InvalidArgumentException('json_decode error');
                }
            } else {
                $result = json_decode($result, true) ?? $result;
            }
        }
        return $result;
    }

    /**
     * @param string $key
     * @return int
     */
    public function delete(string $key)
    {
        $keys = [$key];
        if ($this->isPredisDriver) {
            return $this->driver->del($keys);
        }else {
            return $this->driver->del(...$keys);
        }
    }

    /**
     * @param array $keys
     * @param $default
     * @return array
     */
    public function getMultiple(array $keys)
    {
        $result = $this->driver->mget($keys);
        foreach ($result as &$item) {
            $item = json_decode($item, true) ?? $item;
        }
        return $result;
    }

    /**
     * @param array $values  ['key1' => 'bar', 'key2' => 'bop']
     * @param int|null $ttl
     * @param bool $isOverwrite 是否覆盖已设置的key值（即重新设置）
     * @return bool
     */
    public function setMultiple(array $values, ?int $ttl = null, bool $isOverwrite = true): bool
    {
        $keys       = array_keys($values);
        $valueItems = array_values($values);
        foreach ($valueItems as &$item) {
            if (is_array($item)) {
                $item = json_encode($item, JSON_UNESCAPED_UNICODE);
            }
        }

        // TTL 为 null 时，不使用 Lua 脚本，直接批量设置
        if (is_null($ttl)) {
            if($isOverwrite) {
                $this->driver->mset(array_combine($keys, $valueItems));
            }else {
                $this->driver->msetnx(array_combine($keys, $valueItems));
            }
            return true;
        }

        // TTL 有值时使用 Lua 脚本保证原子性
        if ($isOverwrite) {
            $luaScript = <<<LUA
        for i = 1, #KEYS do
            redis.call('SET', KEYS[i], ARGV[i])
            redis.call('EXPIRE', KEYS[i], ARGV[#KEYS + 1])
        end
        return 'OK'
LUA;
        } else {
            $luaScript = <<<LUA
        for i = 1, #KEYS do
            if redis.call('EXISTS', KEYS[i]) == 0 then
                redis.call('SET', KEYS[i], ARGV[i])
                redis.call('EXPIRE', KEYS[i], ARGV[#KEYS + 1])
            end
        end
        return 'OK'
LUA;
        }
        $args       = array_merge($valueItems, [$ttl]);
        $mixedArgs  = array_merge($keys, $args);
        if ($this->isPredisDriver) {
            $result = $this->driver->eval($luaScript, count($keys), ...$mixedArgs);
        }else {
            $result = $this->driver->eval($luaScript, $mixedArgs, count($keys));
        }
        if ($result != 'OK') {
            return false;
        }
        return true;
    }

    /**
     * @param array $keys
     * @return bool
     */
    public function deleteMultiple(array $keys)
    {
        if ($this->isPredisDriver) {
            $this->driver->del($keys);
        }else {
            $this->driver->del(...$keys);
        }

        return true;
    }

    /**
     * @param string $key
     * @return int
     */
    public function has(string $key): int
    {
        if ($this->isPredisDriver) {
            return $this->driver->exists($key);
        } else {
            $result = $this->driver->exists($key);
            if (is_numeric($result)) {
                return $result;
            } else {
                return 0;
            }
        }
    }

    /**
     * @param string $key
     * @return int
     */
    public function ttl(string $key): int
    {
        return $this->driver->ttl($key);
    }

}