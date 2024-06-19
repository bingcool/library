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

use PhpAmqpLib\Message\AMQPMessage;

class AmqpDelayTopicQueue extends AmqpTopicAbstract {

    use AmqpDelayPublishTrait;
    use AmqpDelayConsumerTrait;

    /**
     * @param AMQPMessage $message
     * @param string $routingKey
     * @param bool $mandatory
     * @param bool $immediate
     * @param $ticket
     * @return mixed|void
     */
    public function publish(AMQPMessage $message, string $routingKey, bool $mandatory = false, bool $immediate = false, $ticket = null)
    {
        if(empty($this->channel)) {
            $this->channel = $this->amqpConnection->channel();
        }

        if($this->ackHandler) {
            $this->channel->set_ack_handler($this->ackHandler);
        }

        if($this->nackHandler) {
            $this->channel->set_nack_handler($this->nackHandler);
        }

        if($this->ackHandler || $this->nackHandler) {
            $this->channel->confirm_select(false);
        }

        // 声明延迟队列
        $this->exchangeDeclareDelay();
        $this->queueDeclareDelay();
        $this->queueBindDelay();

        $this->exchangeDeclare();
        $this->queueDeclare();
        $this->queueBind();
        $this->channel->basic_publish($message, $this->amqpConfig->exchangeName, $routingKey, $mandatory, $immediate, $ticket);
        // waiting for ack
        if($this->ackHandler || $this->nackHandler) {
            $this->channel->wait_for_pending_acks();
        }
    }
}