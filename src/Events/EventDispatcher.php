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

/**
 * class EventDispatcher
 * @package Common\Library\Events
 */

class EventDispatcher
{
    /**
     * EventDispatcher constructor.
     */
    public function __construct()
    {
    }

    /**
     * @param AbstractListener $listenerEvent
     */
    public function dispatch(AbstractListener $listenerEvent)
    {
        $data = $listenerEvent->getData();
        $listeners = $listenerEvent->listen();
        foreach ($listeners as $className) {
            $event = new $className();
            if($event instanceof AbstractEventHandle) {
                $return = $event->handle($data, $listenerEvent);
                $listenerEvent->setResult($className, $return ?? null);
            }
        }

        return $listenerEvent;
    }

    /**
     * @return static
     */
    public static function getInstance()
    {
        $dispatcher = new static();
        return $dispatcher;
    }
}