<?php

namespace Common\Library\Lock;

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

    public function __construct(array $redisAPIs, string $name, int $timeout = 3)
    {
        $this->timeOut = $timeout;
        $this->key = self::PREFIX . $name;
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
            if ($this->isCoroutine()) {
                $chan = new \Swoole\Coroutine\Channel(1);
            }

            $codeResult = $code();

            if ($chan ?? null) {
                $chan->pop($this->timeOut + 1);
                $chan->close();
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