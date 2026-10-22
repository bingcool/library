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

use Malkusch\Lock\Exception\LockReleaseException;
use Malkusch\Lock\Exception\MutexException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * synchronized() 的 Swoole 协程实现，供 RedisMutex / MySQLMutex / PostgreSQLMutex 共用。
 *
 * 语义（覆盖上游的同步实现）：
 * - 在调用方协程获取锁，回调通过 goApp() 放到子协程执行（独立 App/组件上下文）；
 * - 子协程在 defer 中释放锁，父协程等到释放完成后才返回，不会在临界区未结束时提前返回；
 * - 释放失败时抛 LockReleaseException，业务返回值与业务异常分别通过
 *   getCodeResult()/getCodeException() 取回；
 * - 非协程环境（CLI、未启用 Swoole 协程的单测）回退上游同步实现。
 *
 * 连接交接：Redis 连接与 PDO 连接都在「父协程挂起等待、子协程独占使用」的顺序下交接，
 * 属于受控交接；回调内需要新的连接/组件时应从 Application::getApp() 重新获取，
 * 不要复用父协程闭包里捕获的连接。
 */
trait CoroutineSynchronizedTrait
{
    /**
     * 子协程启动握手超时（秒）。goApp() 会吞掉 EventApp 初始化异常，
     * 没有握手时父协程会永久阻塞在结果通道上。
     */
    private const COROUTINE_START_TIMEOUT = 1.0;

    /**
     * 获取锁后在子协程执行临界区，释放完成后把结果/异常回传给调用方协程。
     *
     * 与上游实现的差异：
     * - 回调不在调用方协程内联执行，避免业务代码阻塞时无法在父协程侧处理取消/清理；
     * - 释放动作放在子协程 defer 中，子协程异常结束或被取消时仍会尝试释放；
     * - 等待临界区真正结束（不设置等待上限），不会出现「返回时锁还没释放」的窗口。
     *
     * @template T
     * @param callable():T $fx 临界区回调；兼容旧版会额外传入当前 mutex 实例，零参闭包可忽略
     * @return T
     * @throws \Throwable 回调抛出的业务异常
     * @throws \Malkusch\Lock\Exception\LockAcquireException 获取锁失败
     * @throws LockReleaseException 释放失败，业务结果/异常挂在异常对象上
     */
    #[\Override]
    public function synchronized(callable $fx)
    {
        if (!class_exists(Coroutine::class) || Coroutine::getCid() < 0) {
            return parent::synchronized($fx);
        }

        $this->lock();

        $startedChannel = new Channel(1);
        $outcomeChannel = new Channel(1);
        $aborted = false;

        try {
            $this->spawnCoroutine(function () use ($fx, $startedChannel, $outcomeChannel, &$aborted): void {
                if ($aborted) {
                    // 父协程已因启动超时释放锁并放弃本次临界区，子协程不得在无锁状态下执行
                    return;
                }

                $startedChannel->push(true);

                $codeResult = null;
                $codeException = null;

                Coroutine::defer(function () use (&$codeResult, &$codeException, $outcomeChannel): void {
                    try {
                        $this->unlock();
                    } catch (LockReleaseException $lockReleaseException) {
                        $lockReleaseException->setCodeResult($codeResult);
                        if ($codeException !== null) {
                            $lockReleaseException->setCodeException($codeException);
                        }
                        $outcomeChannel->push($lockReleaseException);
                        return;
                    } catch (\Throwable $releaseException) {
                        $outcomeChannel->push($releaseException);
                        return;
                    }

                    $outcomeChannel->push([
                        'result' => $codeResult,
                        'exception' => $codeException,
                    ]);
                });

                try {
                    $codeResult = $fx($this);
                } catch (\Throwable $exception) {
                    $codeException = $exception;
                }
            });
        } catch (\Throwable $launchException) {
            $aborted = true;
            try {
                $this->unlock();
            } catch (\Throwable) {
                // 启动失败时释放是尽力而为，保留原始启动异常
            }
            throw $launchException;
        }

        $started = $startedChannel->pop(self::COROUTINE_START_TIMEOUT);
        if ($started !== true) {
            // 定时器与 push 同 tick 时 pop 可能超时返回 false，但数据已入队，需非阻塞补取
            $started = $startedChannel->pop(0);
        }

        if ($started !== true && $outcomeChannel->length() === 0) {
            $aborted = true;
            try {
                $this->unlock();
            } catch (\Throwable) {
                // 子协程未启动时释放是尽力而为，保留启动失败异常
            }
            throw new MutexException(sprintf(
                'The synchronized coroutine did not start within %s seconds',
                self::COROUTINE_START_TIMEOUT
            ));
        }

        $outcome = $outcomeChannel->pop(-1);

        if ($outcome === false) {
            throw new MutexException('The synchronized coroutine was cancelled before the critical section finished');
        }

        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        if ($outcome['exception'] !== null) {
            throw $outcome['exception'];
        }

        return $outcome['result'];
    }

    /**
     * goApp() 为回调建立独立的 App/组件上下文，子协程结束时自动清理。
     */
    private function spawnCoroutine(callable $callback): void
    {
        $cid = goApp($callback);

        if ($cid === false) {
            throw new MutexException('Failed to create a coroutine for the synchronized callback');
        }
    }
}
