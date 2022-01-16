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

namespace Common\Library\Events;

abstract class AbstractListener
{

    /**
     * @var array
     */
    protected $data;

    /**
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @return string[] returns the events that you want to listen
     */
    abstract public function listen(): array;

    /**
     * Handle the AbstractEventHandle when the event is triggered, all listeners will
     * complete before the event is returned to the EventDispatcher.
     */
    public function process($event)
    {

    }
}