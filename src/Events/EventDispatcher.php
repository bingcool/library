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

class EventDispatcher
{
    public function __construct()
    {
    }

    public function dispatch(AbstractListener $listenerEvent)
    {
        $listeners = $listenerEvent->listen();
        foreach($listeners as $event) {

        }
    }
}