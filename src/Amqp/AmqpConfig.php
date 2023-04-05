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

class AmqpConfig {

    /**
     * @var string
     */
   public $exchangeName;

    /**
     * @var string
     */
   public $queueName;

    /**
     * @var string
     */
   public $consumerTag = '';

    /**
     * @var string
     */
   public $type;

    /**
     * @var bool
     */
   public $passive = false;

    /**
     * @var bool
     */
   public $durable = false;

    /**
     * @var bool
     */
   public $exclusive = false;

    /**
     * @var bool
     */
   public $autoDelete = false;

    /**
     * @var string
     */
   public $bindingKey = '';

    /**
     * @var string
     */
   public $routingKey = '';

    /**
     * @var bool
     */
   public $internal = false;

    /**
     * @var bool
     */
   public $nowait = false;

    /**
     * @var array
     */
   public $arguments = [];

    /**
     * @var int|null
     */
   public $ticket = null;

}