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
use malkusch\lock\exception\LockReleaseException;

trait SynchronizeTrait
{
    /**
     * @param callable $fn
     * @return callable|mixed
     * @throws Throwable
     */
    public function synchronized(callable $fn)
    {
        $this->lock();
        $codeResult = null;
        $waitChannel = new \Swoole\Coroutine\Channel(1);
        $resultChannel = new \Swoole\Coroutine\Channel(1);
        $returnResultBoolFlag = false;
        goApp(function () use ($fn, $waitChannel, $resultChannel, &$returnResultBoolFlag) {
            \Swoole\Coroutine::defer(function () use ($waitChannel, $resultChannel) {
                try {
                    $result = $this->releaseLock();
                } catch (LockReleaseException $lockReleaseException) {
                }
                $waitChannel->push(1);
            });

            try {
                $codeResult = $fn($this);
                if (is_bool($codeResult)) {
                    $codeResult = intval($codeResult);
                    $returnResultBoolFlag = true;
                }
                $resultChannel->push($codeResult, 0.1);
            }catch (\Throwable $exception) {
                $resultChannel->push($exception);
            }
        });
        /**
         * $fn执行业务逻辑的时间大于$this->timeOut锁的过期时间时，$waitChannel->pop($this->timeOut) 时间到了，就不会再阻塞了
         * 但$codeResult = $fn($this);业务还在执行, 还没有执行$resultChannel->push($codeResult, 0.1);，所以要判断$resultChannel->length()是否等于0
         * 如果length=0,再循环等待$resultChannel->pop($this->timeOut) 直至获取到结果
        */
        $waitChannel->pop($this->timeOut);
        $breakFlag = true;
        $loopTimes = 0;
        do {
//            if ($loopTimes > 5) {
//                break;
//            }
            if ($resultChannel->length() == 0) {
                $codeResult = $resultChannel->pop($this->timeOut);
                if ($codeResult === false) {
                    $breakFlag = false;
                } else {
                    break;
                }
                $loopTimes++;
            } else {
                $codeResult = $resultChannel->pop(0.1);
                break;
            }
        }while(!$breakFlag);

        if ($returnResultBoolFlag === true) {
            if ($codeResult === 1) {
                $codeResult = true;
            } else {
                $codeResult = false;
            }
        }

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
        if (class_exists('Swoole\\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            return true;
        }

        return false;
    }

}