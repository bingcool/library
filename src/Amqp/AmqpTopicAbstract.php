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

abstract class AmqpTopicAbstract {

    use AmqpTrait;

    /**
     * @param AMQPMessage $message
     * @param string $routingKey
     * @param bool $mandatory
     * @param bool $immediate
     * @param $ticket
     * @return mixed
     */
    abstract public function publish(AMQPMessage $message, string $routingKey, bool $mandatory = false, bool $immediate = false, $ticket = null);

    /**
     * @param callable|null $callback
     * @param bool $noLocal
     * @param bool $noAck
     * @param bool $exclusive
     * @param bool $nowait
     * @return mixed
     */
    abstract public function consumer(callable $callback = null, bool $noLocal = false, bool $noAck = false, bool $exclusive = false, bool $nowait = false);

    /**
     * @param callable|null $callback
     * @param float $timeSleep
     * @param bool $noLocal
     * @param bool $noAck
     * @param bool $exclusive
     * @param bool $nowait
     * @return mixed
     */
    abstract public function consumerWithTime(callable $callback = null, float $timeSleep, bool $noLocal = false, bool $noAck = false, bool $exclusive = false, bool $nowait = false);
}