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

namespace Common\Library\Redis;

/**
 * @see \RedisCluster
 * @mixin \RedisCluster
 */
class RedisCluster extends RedisConnection
{
    /**
     * @var \RedisCluster
     */
    protected $redisCluster;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var array
     */
    protected $seeds;

    /**
     * @var float
     */
    protected $timeout;

    /**
     * @var float
     */
    protected $readTimeout;

    /**
     * @var bool
     */
    protected $persistent;

    /**
     * @var string
     */
    protected $auth;

    /**
     * @var array
     */
    private $constructParams = [];

    /**
     * RedisCluster constructor.
     * @param string $name
     * @param $seeds
     * @param float $timeout
     * @param float $readTimeout
     * @param bool $persistent
     * @throws \RedisClusterException
     */
    public function __construct(
        string $name,
        $seeds,
        float $timeout = 1.5,
        float $readTimeout = 1.5,
        bool $persistent = false,
        ?string $auth = null
    )
    {
        $this->name = $name;
        $this->seeds = $seeds;
        $this->timeout = $timeout;
        $this->readTimeout = $readTimeout;
        $this->persistent = $persistent;
        $this->auth = $auth;
        $this->constructParams = func_get_args();
        $this->buildRedisCluster();
    }

    /**
     * buildRedis
     * @throws \RedisClusterException
     */
    protected function buildRedisCluster()
    {
        try {
            $this->redisCluster = new \RedisCluster($this->name, $this->seeds, $this->timeout, $this->readTimeout, $this->persistent, $this->auth);
        } catch (\RedisClusterException $exception) {
            $this->log(__METHOD__, $this->constructParams, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws \RedisClusterException
     * @throws \Throwable
     */
    public function __call(string $method, array $arguments)
    {
        try {
            $this->log($method, $arguments, "redisCluster start to exec method={$method}");
            $result = $this->redisCluster->{$method}(...$arguments);
            $this->log($method, $arguments);
            return $result;
        } catch (\RedisClusterException|\Exception $exception) {
            $this->log($method, $arguments, $exception->getMessage());
            $this->log($method, $arguments, 'redisCluster start to reBuild instance');
            $this->sleep(0.5);
            @$this->redisCluster->close();
            // rebuild RedisCluster
            $this->buildRedisCluster();
            $this->log($method, $arguments, "RedisCluster rebuild instance successful, start to try exec method={$method} again");
            $result = $this->redisCluster->{$method}(...$arguments);
            $this->log($method, $arguments, 'RedisCluster exec retry ok');
            return $result;
        } catch (\Throwable $throwable) {
            $this->log($method, $arguments, 'RedisCluster retry exec failed,errorMsg=' . $throwable->getMessage());
            throw $throwable;
        }
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments)
    {
        return \RedisCluster::{$name}(...$arguments);
    }

    /**
     * @param $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->redisCluster->{$name};
    }

    /**
     * @return \RedisCluster
     */
    public function getRedisClusterInstance()
    {
        return $this->redisCluster;
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        parent::__destruct();
        if (!$this->persistent) {
            @$this->redisCluster->close();
        }
    }
}