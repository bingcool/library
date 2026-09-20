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

namespace Swoolefy\Library\Redis;

/**
 * Redis Cluster 封装。与单机 PHPRedis 共用 {@see RedisConnection::callWithRetry()} 和同一套白名单。
 * Cluster 不支持 SELECT，reConnect 只按原构造参数重建，不恢复 db index。
 *
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
        parent::__construct();
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
     * 命令入口与 PHPRedis 相同：原生 RedisCluster 方法名进入 Retry Policy。
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws \RedisClusterException
     * @throws \Throwable
     */
    public function __call(string $method, array $arguments)
    {
        return $this->callWithRetry($method, $arguments, function (string $method, array $arguments) {
            return $this->redisCluster->{$method}(...$arguments);
        });
    }

    /**
     * Cluster close 在节点已断开时可能告警，忽略后按原 seeds/auth 重建。
     */
    protected function closeNativeConnection(): void
    {
        if (!$this->redisCluster) {
            return;
        }
        try {
            $this->redisCluster->close();
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * 用构造时的 name/seeds/timeout/auth/persistent 重建整个 Cluster 客户端。
     */
    protected function reConnect()
    {
        $this->buildRedisCluster();
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