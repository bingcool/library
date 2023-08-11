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

use malkusch\lock\exception\LockReleaseException;
use Throwable;

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
     * @param int $timeout
     */
    public function __construct(array $redisAPIs, string $name, int $timeout = 3)
    {
        $this->timeOut = $timeout;
        $this->key = self::PREFIX . $name;
        parent::__construct($redisAPIs, $name, $timeout);
    }

}