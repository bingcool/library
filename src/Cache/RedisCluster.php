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

namespace Common\Library\Cache;

/**
 * @see \RedisCluster
 * @mixin \RedisCluster
 */

class RedisCluster extends RedisConnection {
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
        bool $persistent = false
    )
    {
        $this->name = $name;
        $this->seeds = $seeds;
        $this->timeout = $timeout;
        $this->readTimeout = $readTimeout;
        $this->persistent = $persistent;
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
            $this->redisCluster = new \RedisCluster($this->name, $this->seeds, $this->timeout, $this->readTimeout, $this->persistent);
        }catch (\RedisClusterException $exception)
        {
            $this->log(__METHOD__, $this->constructParams, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @param $method
     * @param $arguments
     * @return mixed
     * @throws \RedisClusterException
     * @throws \Throwable
     */
    public function __call($method, $arguments)
    {
        try {
            $this->log($method, $arguments,"redisCluster start to exec method={$method}");
            $result = $this->redisCluster->{$method}(...$arguments);
            $this->log($method, $arguments);
            return $result;
        }catch(\RedisClusterException|\Exception $e) {
            $this->log($method, $arguments, $e->getMessage());
            $this->log($method, $arguments, 'redisCluster start to reBuild instance');
            $this->sleep(0.5);
            @$this->redisCluster->close();
            // rebuild RedisCluster
            $this->buildRedisCluster();
            $this->log($method, $arguments, "RedisCluster rebuild instance successful, start to try exec method={$method} again");
            $result = $this->redisCluster->{$method}(...$arguments);
            $this->log($method, $arguments,'RedisCluster exec retry ok');
            return $result;
        }catch(\Throwable $t) {
            $this->log($method, $arguments, 'RedisCluster retry exec failed,errorMsg='.$t->getMessage());
            throw $t;
        }
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return \RedisCluster::{$name}(...$arguments);
    }

    /**
     * @param $name
     * @return mixed
     */
    public function __get($name)
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
        @$this->redisCluster->close();
    }
}