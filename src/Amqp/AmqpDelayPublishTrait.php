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

use PhpAmqpLib\Wire\AMQPTable;

trait AmqpDelayPublishTrait
{
    /**
     * @var array|AMQPTable
     */
    protected $arguments;

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
}