<?php
namespace Common\library\Lock;

use malkusch\lock\exception\LockReleaseException;
use Throwable;

/**
 * +----------------------------------------------------------------------
* | Common library of swoole
* +----------------------------------------------------------------------
* | Licensed ( https://opensource.org/licenses/MIT )
* +----------------------------------------------------------------------
* | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
* +----------------------------------------------------------------------
 */

class PredisMutex extends \malkusch\lock\mutex\PredisMutex
{
    /**
     * @var int The timeout in seconds a lock may live.
     */
    protected $timeout;

    /**
     * @var string The lock key.
     */
    protected $key;

    /**
     * @var double The timestamp when the lock was acquired.
     */
    protected $acquired;

    public function __construct(array $redisAPIs, string $name, int $timeout = 3)
    {
        $this->timeOut = $timeout;
        parent::__construct($redisAPIs, $name, $timeout);
    }

    /**
     * {@inheritDoc}
     */
    public function synchronized(callable $code)
    {
        $this->lock();

        $codeResult = null;
        $codeException = null;
        try {
            if($this->isCoroutine())
            {
                $chan = new \Swoole\Coroutine\Channel(1);
            }

            $codeResult = $code();

            if($chan ?? null)
            {
                $chan->pop($this->timeout + 1);
            }
        } catch (Throwable $exception) {
            $codeException = $exception;

            throw $exception;
        } finally {
            try {
                $this->unlock();
            } catch (LockReleaseException $lockReleaseException) {
                $lockReleaseException->setCodeResult($codeResult);
                if ($codeException !== null) {
                    $lockReleaseException->setCodeException($codeException);
                }

                throw $lockReleaseException;
            }
        }

        return $codeResult;
    }

    /**
     * @return bool
     */
    public function getLock(): bool
    {
        $this->acquired = microtime(true);
        return $this->acquire($this->key, $this->timeout + 1);
    }

    /**
     * @return bool
     */
    public function releaseLock(): bool
    {
        try {
            $this->unlock();
            return true;
        } catch (LockReleaseException $lockReleaseException) {
            $lockReleaseException->setCodeResult([]);
            throw $lockReleaseException;
        }
    }

    /**
     * @return bool
     */
    public function isCoroutine()
    {
        if(\Swoole\Coroutine::getCid() > 0)
        {
            return true;
        }

        return false;
    }
}