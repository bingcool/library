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
 * PHPRedis 封装。业务方法（get/incr/eval...）均走 __call，因此 Retry Policy 能覆盖几乎全部命令。
 *
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
     * @var bool
     */
    protected $isPersistent = false;

    /**
     * Redis constructor
     */
    public function __construct()
    {
        parent::__construct();
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
     * 用原始 connect/pconnect 参数重建客户端，然后恢复 AUTH 与 SELECT。
     *
     * auth / select 必须打在原生 `\Redis` 上，避免再进入 __call 造成递归重连。
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
            $this->redis->auth($this->password);
        }
        $this->restoreSelectedDatabase(function (int $database) {
            $this->redis->select($database);
        });
    }

    /**
     * 记录 password，供 reConnect() 恢复。connect() 之后的 AUTH 必须走这里，否则重连会丢密码。
     *
     * @param string $password
     */
    public function auth(string $password)
    {
        $this->password = $password;
        $this->redis->auth($password);
    }

    /**
     * 所有未显式声明的 Redis 命令入口。invoke 闭包打原生实例，由父类决定是否 replay。
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws \Throwable
     */
    public function __call(string $method, array $arguments)
    {
        return $this->callWithRetry($method, $arguments, function (string $method, array $arguments) {
            return $this->redis->{$method}(...$arguments);
        });
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
        $pong = $this->redis->ping();
        if ($pong === true || $pong === '+PONG' || $pong === 'PONG') {
            return true;
        }
        return false;
    }

    /**
     * 死连接上 close() 常会再抛一次，忽略后交给 reConnect() 重建。
     */
    protected function closeNativeConnection(): void
    {
        if (!$this->redis) {
            return;
        }
        try {
            $this->redis->close();
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        parent::__destruct();
        if (!$this->isPersistent && $this->redis) {
            try {
                $this->redis->close();
            } catch (\Throwable $ignored) {
            }
        }
    }
}
