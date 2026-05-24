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

namespace Swoolefy\Library\Lock;

class PredisMutex extends \malkusch\lock\mutex\PredisMutex
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
     * @param int $timeout // 出单位秒. 锁的超时释放时间.
     * 并发情况下，不同请求实例获取锁的最大等待时间，在这个时间内获取不到锁将抛出\malkusch\lock\exception\TimeoutException超时异常
     * 业务侧需要捕捉这个异常返回给前端请求
     */
    public function __construct(array $redisAPIs, string $name, int $timeout = 3)
    {
        $this->timeOut = $timeout;
        $this->key = self::PREFIX . $name;
        parent::__construct($redisAPIs, $name, $timeout);
    }

}