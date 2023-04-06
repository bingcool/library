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
use PhpAmqpLib\Wire\AMQPTable;

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

    /**
     * @var array|AMQPTable
     */
    protected $arguments;

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
            $this->parseArguments(),
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
            $this->parseArguments(),
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

    /**
     * @return mixed
     */
    protected function parseArguments() {
        if (!is_null($this->arguments)) {
            return $this->arguments;
        }

        if(!empty($this->amqpConfig->arguments)) {
            $arguments = [];
            if(isset($this->amqpConfig->arguments['x-dead-letter-exchange'])) {
                $arguments['x-dead-letter-exchange'] = $this->amqpConfig->arguments['x-dead-letter-exchange'];
            }

            if(isset($this->amqpConfig->arguments['x-dead-letter-routing-key'])) {
                $arguments['x-dead-letter-routing-key'] = $this->amqpConfig->arguments['x-dead-letter-routing-key'];
            }

            if(isset($this->amqpConfig->arguments['x-message-ttl']) && is_numeric($this->amqpConfig->arguments['x-message-ttl'])) {
                $arguments['x-message-ttl'] =  $this->amqpConfig->arguments['x-message-ttl'];
            }

            if(isset($this->amqpConfig->arguments['x-expires']) && is_numeric($this->amqpConfig->arguments['x-expires'])) {
                $arguments['x-expires'] = $this->amqpConfig->arguments['x-expires'];
            }

            if(isset($this->amqpConfig->arguments['x-max-length']) && is_numeric($this->amqpConfig->arguments['x-max-length'])) {
                $arguments['x-max-length'] = $this->amqpConfig->arguments['x-max-length'];
            }

            if(isset($this->amqpConfig->arguments['x-max-priority']) && is_numeric($this->amqpConfig->arguments['x-max-priority'])) {
                $arguments['x-max-priority'] = $this->amqpConfig->arguments['x-max-priority'];
            }

            if(isset($this->amqpConfig->arguments['x-ha-policy'])) {
                $arguments['x-ha-policy'] = $this->amqpConfig->arguments['x-ha-policy'];
            }

            if(isset($this->amqpConfig->arguments['x-ha-policy-params'])) {
                $arguments['x-ha-policy-params'] = $this->amqpConfig->arguments['x-ha-policy-params'];
            }

            if(!empty($arguments)) {
                $this->arguments = new AMQPTable($arguments);
            }else {
                $this->arguments = $arguments;
            }
        }else {
            $this->arguments = $this->amqpConfig->arguments;
        }

        return $this->arguments;
    }

    /**
     * @throws \Exception
     */
    public function __destruct()
    {
        $this->close();
    }


}