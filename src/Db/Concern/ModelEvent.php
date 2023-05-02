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

namespace Common\Library\Db\Concern;

use Common\Library\Exception\DbException;

/**
 * Trait ModelEvent
 * @package Common\Library\Db\Concern
 */
trait ModelEvent
{
    /**
     * 是否需要事件响应
     * @var bool
     */
    private $withEvent = true;

    /**
     * 某些场景下需要设置忽略执行的事件
     * @var array
     */
    private $skipEvents = [];

    /**
     * 在某些情况下，并不需要执行Model原生定义的事件处理函数，那么提供自定义处理或者设置忽略处理
     * @var array 动态定制事件事件处理回调函数，不使用固定的。如果设置自定义事件，则优先执行自定义事件
     */
    private $customEventHandlers = [];


    /**
     * 忽略所有事件的执行, 比如只更新某些字段，并不会有业务需要更新事件的
     * @return $this
     */
    public function withOutAllEvents()
    {
        $this->withEvent = false;
        return $this;
    }

    /**
     * @param string $event
     * @return $this
     */
    public function skipEvent(string $event)
    {
        $this->skipEvents[] = $event;
        return $this;
    }

    /**
     * 触发事件
     * @param string $event 事件名
     * @return bool
     * @throws \Exception
     */
    protected function trigger(string $event): bool
    {
        if (!$this->withEvent) {
            return true;
        }

        $onEvent = self::studly($event);
        $eventFunction = 'on' . $onEvent;

        try {
            $result = null;
            /**@var \Closure $callFunction */
            if (isset($this->customEventHandlers[$onEvent]) && $this->customEventHandlers[$onEvent] instanceof \Closure) {
                $callFunction = $this->customEventHandlers[$onEvent];
                $result = $callFunction->call($this);
            } else {
                if (method_exists(static::class, $eventFunction) && !in_array($onEvent, $this->skipEvents)) {
                    $result = $this->{$eventFunction}();
                }
            }
            return false === $result ? false : true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @param string $event
     * @param \Closure $func
     * @throws DbException
     */
    public function setEventHandle(string $event, \Closure $func)
    {
        if (!in_array($event, static::EVENTS)) {
            throw new DbException("AddEventHandle first argument of eventName type error");
        }

        $this->customEventHandlers[$event] = $func;
    }

    /**
     * @param string $event
     * @return \Closure
     */
    public function getEventHandle(string $event = '')
    {
        if ($event) {
            $handle = $this->customEventHandlers[$event] ?? null;
        } else {
            $handle = $this->customEventHandlers;
        }
        return $handle;
    }
}
