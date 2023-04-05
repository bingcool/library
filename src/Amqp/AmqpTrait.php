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

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

trait AmqpTrait
{

    /**
     * @var AMQPStreamConnection
     */
    protected $amqpConnection;

    /**
     * @var AMQPChannel
     */
    protected $channel;

    /**
     * @var AmqpConfig
     */
    protected $amqpConfig;

    /**
     * @var callable
     */
    protected $ackHandler;

    /**
     * @var callable
     */
    protected $nackHandler;

    /**
     * @var callable
     */
    protected $consumerExceptionHandler;

    public function __construct(AMQPStreamConnection $amqpConnection, AmqpConfig $amqpConfig)
    {
        $this->amqpConnection = $amqpConnection;
        $this->amqpConfig = $amqpConfig;
    }

    /**
     * @return void
     */
    protected function exchangeDeclare()
    {
        $this->channel->exchange_declare(
            $this->amqpConfig->exchangeName,
            $this->amqpConfig->type,
            $this->amqpConfig->passive,
            $this->amqpConfig->durable,
            $this->amqpConfig->autoDelete,
            $this->amqpConfig->internal,
            $this->amqpConfig->nowait,
            $this->amqpConfig->arguments,
            $this->amqpConfig->ticket
        );
    }

    /**
     * @return void
     */
    protected function queueDeclare()
    {
        $this->channel->queue_declare(
            $this->amqpConfig->queueName,
            $this->amqpConfig->passive,
            $this->amqpConfig->durable,
            $this->amqpConfig->exclusive,
            $this->amqpConfig->autoDelete,
            $this->amqpConfig->nowait,
            $this->amqpConfig->arguments,
            $this->amqpConfig->ticket
        );
    }

    /**
     * @return void
     */
    protected function queueBind()
    {
        $this->channel->queue_bind($this->amqpConfig->queueName, $this->amqpConfig->exchangeName, $this->amqpConfig->bindingKey);
    }

    /**
     * publish success callback
     *
     * @param callable $ackHandle
     * @return void
     */
    public function setAckHandler(callable $ackHandler)
    {
        $this->ackHandler = $ackHandler;
    }

    /**
     * @param callable $ackHandle
     * @return void
     */
    public function setNackHandler(callable $nackHandler)
    {
        $this->nackHandler = $nackHandler;
    }

    /**
     * @param \Throwable $e
     * @return void
     */
    public function setConsumerExceptionHandler(callable $consumerExceptionHandler)
    {
        $this->consumerExceptionHandler = $consumerExceptionHandler;
    }

    /**
     * @throws \Exception
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function close()
    {
        if (!empty($this->channel)) {
            $this->channel->close();
        }

        if (!empty($this->amqpConnection)) {
            $this->amqpConnection->close();
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
    protected function declareHandle(callable $callback = null, bool $noLocal = false, bool $noAck = false, bool $exclusive = false, bool $nowait = false)
    {
        if(empty($this->channel)) {
            $this->channel = $this->amqpConnection->channel();
        }
        $this->exchangeDeclare();
        $this->queueDeclare();
        $this->queueBind();
        $this->channel->basic_consume($this->amqpConfig->queueName, $this->amqpConfig->consumerTag, $noLocal, $noAck, $exclusive, $nowait, $callback);
    }
}