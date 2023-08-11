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

use Throwable;
use Swoolefy\Core\BaseServer;
use malkusch\lock\exception\LockReleaseException;

trait SynchronizeTrait
{
    /**
     * @param callable $code
     * @return callable|mixed
     * @throws Throwable
     */
    public function synchronized(callable $code)
    {
        $this->lock();
        $codeResult = null;
        $waitChannel = new \Swoole\Coroutine\Channel(1);
        $resultChannel = new \Swoole\Coroutine\Channel(1);
        goApp(function () use ($code, $waitChannel, $resultChannel) {
            \Swoole\Coroutine::defer(function () use ($waitChannel, $resultChannel) {
                try {
                    $result = $this->releaseLock();
                } catch (LockReleaseException $lockReleaseException) {
                }
                $waitChannel->push(1);
            });

            try {
                $codeResult = $code($this);
                $resultChannel->push($codeResult, 0.1);
            }catch (\Throwable $exception) {
                $resultChannel->push($exception);
            }
        });

        $waitChannel->pop($this->timeOut);
        $codeResult = $resultChannel->pop(0.1);
        if ($codeResult instanceof \Throwable) {
            throw $codeResult;
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