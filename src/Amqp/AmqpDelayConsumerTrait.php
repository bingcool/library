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

use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPIOWaitException;

trait AmqpDelayConsumerTrait
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

                if($this->amqpConnection->getHeartbeat() > 0) {
                    $sender = new PCNTLHeartbeatSender($this->amqpConnection);
                    $sender->register();
                }

                $this->channel->basic_qos(0,1,false);

                $this->declareHandleDelay($callback, $noLocal, $noAck, $exclusive, $nowait);

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

    /**
     * @param callable|null $callback
     * @param bool $noLocal
     * @param bool $noAck
     * @param bool $exclusive
     * @param bool $nowait
     * @return void
     */
    protected function declareHandleDelay(callable $callback = null, bool $noLocal = false, bool $noAck = false, bool $exclusive = false, bool $nowait = false)
    {
        if(empty($this->channel)) {
            $this->channel = $this->amqpConnection->channel();
        }
        $this->exchangeDeclareDelay();
        $this->queueDeclareDelay();
        $this->queueBindDelay();
        $this->channel->basic_consume(
            $this->amqpConfig->arguments['x-dead-letter-queue'],
            $this->amqpConfig->consumerTag,
            $noLocal,
            $noAck,
            $exclusive,
            $nowait,
            $callback
        );
    }

    /**
     * @return void
     */
    protected function exchangeDeclareDelay()
    {
        $this->channel->exchange_declare(
            $this->amqpConfig->arguments['x-dead-letter-exchange'],
            $this->amqpConfig->type,
            $this->amqpConfig->passive,
            $this->amqpConfig->durable,
            $this->amqpConfig->autoDelete,
            $this->amqpConfig->internal,
            $this->amqpConfig->nowait,
            [],
            $this->amqpConfig->ticket
        );
    }

    /**
     * @return void
     */
    protected function queueDeclareDelay()
    {
        $this->channel->queue_declare(
            $this->amqpConfig->arguments['x-dead-letter-queue'],
            $this->amqpConfig->passive,
            $this->amqpConfig->durable,
            $this->amqpConfig->exclusive,
            $this->amqpConfig->autoDelete,
            $this->amqpConfig->nowait,
            [],
            $this->amqpConfig->ticket
        );
    }

    /**
     * @return void
     */
    protected function queueBindDelay()
    {
        $this->channel->queue_bind(
            $this->amqpConfig->arguments['x-dead-letter-queue'],
            $this->amqpConfig->arguments['x-dead-letter-exchange'],
            $this->amqpConfig->arguments['x-dead-letter-routing-key']
        );
    }
}