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

namespace Common\Library\Amqp;

use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPIOWaitException;

trait AmqpConsumerTrait
{
    /**
     * @param callable|null $callback
     * @param bool $noLocal
     * @param bool $noAck
     * @param bool $exclusive
     * @param bool $nowait
     * @return mixed|void
     * @throws \ErrorException
     */
    public function consumer(callable $callback = null, bool $noLocal = false, bool $noAck = false, bool $exclusive = false, bool $nowait = false)
    {
        $this->consumerWithTime($callback,0.01, $noLocal, $noAck, $exclusive, $nowait);
    }

    /**
     * @param callable|null $callback
     * @param float $timeSleep
     * @param bool $noLocal
     * @param bool $noAck
     * @param bool $exclusive
     * @param bool $nowait
     * @return mixed|void
     */
    public function consumerWithTime(
        callable $callback = null,
        float $timeSleep = 0.1,
        bool $noLocal = false,
        bool $noAck = false,
        bool $exclusive = false,
        bool $nowait = false
    )
    {
        while (true) {
            try {
                if (!$this->amqpConnection->isConnected()) {
                    $this->amqpConnection->reconnect();
                    $this->channel = $this->amqpConnection->channel();
                }
                $this->declareHandle($callback, $noLocal, $noAck, $exclusive, $nowait);
                while ($this->channel->is_consuming()) {
                    $this->channel->wait();
                    // do something else
                    if($timeSleep < 1) {
                        usleep($timeSleep * 1000000);
                    }else {
                        sleep((int)$timeSleep);
                    }
                }
            }catch (AMQPIOException $exception) {
                $this->close();
                usleep(1 * 1000000);
            }catch (AMQPIOWaitException | \Throwable $exception) {
                $this->close();
                usleep(0.3 * 1000000);
            } finally {
                if(isset($exception) && is_callable($this->consumerExceptionHandler)) {
                    try {
                        call_user_func($this->consumerExceptionHandler, $exception);
                    }catch (\Throwable $e) {
                        // do not something
                    }
                }
            }
        }
    }
}