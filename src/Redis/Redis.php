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
 * @see \Redis
 * @mixin \Redis
 */
class Redis extends RedisConnection
{

    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var string
     */
    protected $password;

    /**
     * @var bool
     */
    protected $isPersistent = false;

    /**
     * Redis constructor
     */
    public function __construct()
    {
        $this->buildRedis();
    }

    /**
     * buildRedis
     */
    protected function buildRedis()
    {
        if (!extension_loaded('redis')) {
            throw new \Exception("Missing extension redis, please install it");
        }
        unset($this->redis);
        $this->redis = new \Redis();
    }

    /**
     * @param string $host
     * @param int $port
     * @param float $timeout
     * @param null $reserved
     * @param int $retry_interval
     * @param float $read_timeout
     * @return $this
     */
    public function connect(
        string $host,
        int $port = 6379,
        float $timeout = 2.0,
        $reserved = null,
        int $retry_interval = 0,
        float $read_timeout = 0.0
    )
    {
        $this->redis->connect($host, $port, $timeout, $reserved, $retry_interval, $read_timeout);
        $this->config = [$host, $port, $timeout, $reserved, $retry_interval, $read_timeout];
        $this->isPersistent = false;
        $this->log(__FUNCTION__, $this->config);
        return $this;
    }

    /**
     * @param string $host
     * @param int $port
     * @param float $timeout
     * @param null $persistent_id
     * @param int $retry_interval
     * @param float $read_timeout
     * @return $this
     */
    public function pconnect(
        string $host,
        int $port = 6379,
        float $timeout = 0.0,
        $persistent_id = null,
        int $retry_interval = 0,
        float $read_timeout = 0.0
    )
    {
        $this->redis->pconnect($host, $port, $timeout, $persistent_id, $retry_interval, $read_timeout);
        $this->config = [$host, $port, $timeout, $persistent_id, $retry_interval, $read_timeout];
        $this->isPersistent = true;
        $this->log(__FUNCTION__, $this->config);
        return $this;
    }

    /**
     * reConnect
     */
    protected function reConnect()
    {
        $config = $this->config;
        $this->buildRedis();
        if ($this->isPersistent) {
            $this->pconnect(...$config);
        } else {
            $this->connect(...$config);
        }
        if ($this->password) {
            $this->auth($this->password);
        }
    }

    /**
     * @param string $password
     */
    public function auth(string $password)
    {
        $this->password = $password;
        $this->redis->auth($password);
    }

    /**
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws \Throwable
     */
    public function __call(string $method, array $arguments)
    {
        try {
            $result = $this->redis->{$method}(...$arguments);
            $this->log($method, $arguments);
            return $result;
        } catch (\RedisException|\Exception $exception) {
            $this->sleep(0.5);
            $this->redis->close();
            $this->reConnect();
            $result = $this->redis->{$method}(...$arguments);
            return $result;
        } catch (\Throwable $throwable) {
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
        return \Redis::{$name}(...$arguments);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->redis->{$name};
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return \Redis
     */
    public function getRedisInstance()
    {
        return $this->redis;
    }

    /**
     * @return bool
     */
    public function isConnect()
    {
        if ($this->redis->ping() == '+PONG') {
            return true;
        }
        return false;
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        parent::__destruct();
        if (!$this->isPersistent) {
            $this->redis->close();
        }
    }
}