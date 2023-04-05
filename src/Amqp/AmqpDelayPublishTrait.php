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
     * @return mixed
     */
    protected function parseArguments() {
        if (!is_null($this->arguments)) {
            return $this->arguments;
        }
        if(isset($this->amqpConfig->arguments['x-dead-letter-exchange'])) {
            $this->arguments = new AMQPTable([
                'x-dead-letter-exchange' => $this->amqpConfig->arguments['x-dead-letter-exchange'], //在同一个交换机下，这个不要改变
                'x-dead-letter-routing-key' => $this->amqpConfig->arguments['x-dead-letter-routing-key'], // 死信队列binding key
                'x-message-ttl' => $this->amqpConfig->arguments['x-message-ttl']
            ]);
        }else {
            $this->arguments = $this->amqpConfig->arguments;
        }

        return $this->arguments;
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
            $this->amqpConfig->autoDelete,
            $this->amqpConfig->internal,
            $this->amqpConfig->nowait,
            $this->parseArguments(),
            $this->amqpConfig->ticket
        );
    }
}