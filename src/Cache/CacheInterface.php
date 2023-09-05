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

namespace Common\Library\Cache;

interface CacheInterface
{

    /**
     * @param string $key
     * @param $value
     * @param int|null $ttl
     * @return mixed
     */
    public function set(string $key, $value, ?int $ttl = null);

    /**
     * @param string $key
     * @return mixed
     */
    public function get(string $key);


    /**
     * @param string $key
     * @return mixed
     */
    public function delete(string $key);

    /**
     * @param iterable $keys
     * @return mixed
     */
    public function getMultiple(array $keys);

    /**
     * @param iterable $values
     * @param int|null $ttl
     * @return mixed
     */
    public function setMultiple(array $values, ?int $ttl = null);

    /**
     * @param iterable $keys
     * @return mixed
     */
    public function deleteMultiple(array $keys);

    /**
     * @param string $key
     * @return mixed
     */
    public function has(string $key): int;

    /**
     * @param string $key
     * @return int|false
     */
    public function ttl(string $key): int;
}
