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

class PHPRedisMutex extends \malkusch\lock\mutex\PHPRedisMutex
{
    /**
     * @var int The timeout in seconds a lock may live.
     */
    private $timeout;

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
        } catch (Throwable $exception)
        {
            $codeException = $exception;

            throw $exception;
        } finally
        {
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
    public function isCoroutine()
    {
        if(\Swoole\Coroutine::getCid() > 0)
        {
            return true;
        }

        return false;
    }
}