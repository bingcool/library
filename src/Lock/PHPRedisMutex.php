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

namespace Common\Library\Lock;

use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\BaseObject;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventController;
use Throwable;
use \RedisException;
use \RedisCluster;
use \Redis;

class PHPRedisMutex extends \malkusch\lock\mutex\RedisMutex
{
    use SynchronizeTrait;
    
    /**
     * The prefix for the lock key.
     */
    private const PREFIX = 'lock_';

    /**
     * @var int
     */
    protected $timeOut;

    /**
     * @var string The lock key.
     */
    private $key;

    /**
     * @param array $redisAPIs
     * @param string $name
     * @param int $timeout
     */
    public function __construct(array $redisAPIs, string $name, int $timeout = 3)
    {
        $this->timeOut = $timeout;
        $this->key = self::PREFIX . $name;
        parent::__construct($redisAPIs, $name, $timeout);
    }

   
    /**
     * @return bool
     */
    public function acquireLock(): bool
    {
        return $this->acquire($this->key, $this->timeOut);
    }

    /**
     * @return bool
     */
    public function releaseLock(): bool
    {
        if (!$this->release($this->key)) {
            throw new LockReleaseException('Failed to release the lock.');
        }

        return true;
    }

    /**
     * @param $redisAPI
     * @param string $key
     * @param string $value
     * @param int $expire
     * @return bool
     * @throws \RedisException
     */
    protected function add($redisAPI, string $key, string $value, int $expire): bool
    {
        /** @var \Redis $redisAPI */
        try {
            //  Will set the key, if it doesn't exist, with a ttl of $expire seconds
            return $redisAPI->set($key, $value, ['nx', 'ex' => $expire]);
        } catch (\RedisException $e) {
            $message = sprintf(
                "Failed to acquire lock for key '%s'",
                $key
            );
            throw new LockAcquireException($message, 0, $e);
        }
    }

    /**
     * @param \Redis $redisAPI
     * @param string $script
     * @param int $numkeys
     * @param array $arguments
     * @return mixed
     */
    protected function evalScript($redisAPI, string $script, int $numkeys, array $arguments)
    {
        for ($i = $numkeys; $i < count($arguments); $i++) {
            /*
             * If a serialization mode such as "php" or "igbinary" is enabled, the arguments must be
             * serialized by us, because phpredis does not do this for the eval command.
             *
             * The keys must not be serialized.
             */
            $arguments[$i] = $redisAPI->_serialize($arguments[$i]);

            /*
             * If LZF compression is enabled for the redis connection and the runtime has the LZF
             * extension installed, compress the arguments as the final step.
             */
            if ($this->hasLzfCompression($redisAPI)) {
                $arguments[$i] = lzf_compress($arguments[$i]);
            }
        }

        try {
            return $redisAPI->eval($script, $arguments, $numkeys);
        } catch (\RedisException $e) {
            throw new LockReleaseException('Failed to release lock', 0, $e);
        }
    }

    /**
     * Determines if lzf compression is enabled for the given connection.
     *
     * @param  \Redis|\RedisCluster $redis The Redis or RedisCluster connection.
     * @return bool TRUE if lzf compression is enabled, false otherwise.
     */
    private function hasLzfCompression($redis): bool
    {
        if (!\defined('Redis::COMPRESSION_LZF')) {
            return false;
        }

        return Redis::COMPRESSION_LZF === $redis->getOption(Redis::OPT_COMPRESSION);
    }

    /**
     * @return bool
     */
    public function isCoroutine()
    {
        if (class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            return true;
        }

        return false;
    }
}