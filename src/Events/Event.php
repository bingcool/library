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

use Common\Library\Helper\StringUtil;

/**
 *
 */
class Event
{
    /**
     * @var string the event name. This property is set by [[Component::trigger()]] and [[trigger()]].
     * Events handlers may use this property to check what event it is handling.
     */
    public $name;
    /**
     * @var object the sender of this event. If not set, this property will be
     * set as the object whose `trigger()` method is called.
     * This property may also be a `null` when this event is a
     * class-level event which is triggered in a static context.
     */
    public $sender;
    /**
     * @var bool whether the event is handled. Defaults to `false`.
     * When a handler sets this to be `true`, the event processing will stop and
     * ignore the rest of the uninvoked event handlers.
     */
    public $handled = false;
    /**
     * @var mixed the data that is passed to [[Component::on()]] when attaching an event handler.
     * Note that this varies according to which event handler is currently executing.
     */
    public $data;

    /**
     * @var array contains all globally registered event handlers.
     */
    private $_events = [];
    /**
     * @var array the globally registered event handlers attached for wildcard patterns (event name wildcard => handlers)
     */
    private $_eventWildcards = [];


    /**
     * For more details about how to declare an event handler, please refer to [[Component::on()]].
     *
     * @param string $name the event name.
     * @param string $class the fully qualified class name to which the event handler needs to attach.
     * @param callable $handler the event handler.
     * @param mixed $data the data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[Events::data]].
     * @param bool $append whether to append new event handler to the end of the existing
     * handler list. If `false`, the new handler will be inserted at the beginning of the existing
     * handler list.
     * @see off()
     */
    public function on($name, $class, $handler, $data = null, $append = true)
    {
        $class = ltrim($class, '\\');

        if (strpos($class, '*') !== false || strpos($name, '*') !== false) {
            if ($append || empty($this->_eventWildcards[$name][$class])) {
                $this->_eventWildcards[$name][$class][] = [$handler, $data];
            } else {
                array_unshift($this->_eventWildcards[$name][$class], [$handler, $data]);
            }
            return;
        }

        if ($append || empty($this->_events[$name][$class])) {
            $this->_events[$name][$class][] = [$handler, $data];
        } else {
            array_unshift($this->_events[$name][$class], [$handler, $data]);
        }
    }

    /**
     * @param string $name the event name.
     * @param string $class the fully qualified class name from which the event handler needs to be detached.
     * @param callable $handler the event handler to be removed.
     * If it is `null`, all handlers attached to the named event will be removed.
     * @return bool whether a handler is found and detached.
     * @see on()
     */
    public function off($name, $class, $handler = null)
    {
        $class = ltrim($class, '\\');
        if (empty($this->_events[$name][$class]) && empty($this->_eventWildcards[$name][$class])) {
            return false;
        }
        if ($handler === null) {
            unset($this->_events[$name][$class]);
            unset($this->_eventWildcards[$name][$class]);
            return true;
        }

        // plain event names
        if (isset($this->_events[$name][$class])) {
            $removed = false;
            foreach ($this->_events[$name][$class] as $i => $event) {
                if ($event[0] === $handler) {
                    unset($this->_events[$name][$class][$i]);
                    $removed = true;
                }
            }
            if ($removed) {
                $this->_events[$name][$class] = array_values($this->_events[$name][$class]);
                return $removed;
            }
        }

        // wildcard event names
        $removed = false;
        if (isset($this->_eventWildcards[$name][$class])) {
            foreach ($this->_eventWildcards[$name][$class] as $i => $event) {
                if ($event[0] === $handler) {
                    unset($this->_eventWildcards[$name][$class][$i]);
                    $removed = true;
                }
            }
            if ($removed) {
                $this->_eventWildcards[$name][$class] = array_values($this->_eventWildcards[$name][$class]);
                // remove empty wildcards to save future redundant regex checks :
                if (empty($this->_eventWildcards[$name][$class])) {
                    unset($this->_eventWildcards[$name][$class]);
                    if (empty($this->_eventWildcards[$name])) {
                        unset($this->_eventWildcards[$name]);
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * Detaches all registered class-level event handlers.
     * @see on()
     * @see off()
     */
    public function offAll()
    {
        $this->_events = [];
        $this->_eventWildcards = [];
    }

    /**
     * Returns a value indicating whether there is any handler attached to the specified class-level event.
     * Note that this method will also check all parent classes to see if there is any handler attached
     * to the named event.
     * @param string|object $class the object or the fully qualified class name specifying the class-level event.
     * @param string $name the event name.
     * @return bool whether there is any handler attached to the event.
     */
    public function hasHandlers($class, $name)
    {
        if (empty($this->_eventWildcards) && empty($this->_events[$name])) {
            return false;
        }

        if (is_object($class)) {
            $class = get_class($class);
        } else {
            $class = ltrim($class, '\\');
        }

        $classes = array_merge(
            [$class],
            class_parents($class, true),
            class_implements($class, true)
        );

        // regular events
        foreach ($classes as $className) {
            if (!empty($this->_events[$name][$className])) {
                return true;
            }
        }

        // wildcard events
        foreach ($this->_eventWildcards as $nameWildcard => $classHandlers) {
            if (!StringUtil::matchWildcard($nameWildcard, $name, ['escape' => false])) {
                continue;
            }
            foreach ($classHandlers as $classWildcard => $handlers) {
                if (empty($handlers)) {
                    continue;
                }
                foreach ($classes as $className) {
                    if (StringUtil::matchWildcard($classWildcard, $className, ['escape' => false])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Triggers a class-level event.
     * This method will cause invocation of event handlers that are attached to the named event
     * for the specified class and all its parent classes.
     * @param string|object $class the object or the fully qualified class name specifying the class-level event.
     * @param string $name the event name.
     * @param Event $event the event parameter. If not set, a default [[Events]] object will be created.
     */
    public function trigger($name, $class, $event = null)
    {
        $wildcardEventHandlers = [];
        foreach ($this->_eventWildcards as $nameWildcard => $classHandlers) {
            if (!StringUtil::matchWildcard($nameWildcard, $name)) {
                continue;
            }
            $wildcardEventHandlers = array_merge($wildcardEventHandlers, $classHandlers);
        }

        if (empty($this->_events[$name]) && empty($wildcardEventHandlers)) {
            return;
        }

        if ($event === null) {
            $event = new static();
        }
        $event->handled = false;
        $event->name = $name;

        if (is_object($class)) {
            if ($event->sender === null) {
                $event->sender = $class;
            }
            $class = get_class($class);
        } else {
            $class = ltrim($class, '\\');
        }

        $classes = array_merge(
            [$class],
            class_parents($class, true),
            class_implements($class, true)
        );

        foreach ($classes as $class) {
            $eventHandlers = [];
            foreach ($wildcardEventHandlers as $classWildcard => $handlers) {
                if (StringUtil::matchWildcard($classWildcard, $class, ['escape' => false])) {
                    $eventHandlers = array_merge($eventHandlers, $handlers);
                    unset($wildcardEventHandlers[$classWildcard]);
                }
            }

            if (!empty($this->_events[$name][$class])) {
                $eventHandlers = array_merge($eventHandlers, $this->_events[$name][$class]);
            }

            foreach ($eventHandlers as $handler) {
                $event->data = $handler[1];
                call_user_func($handler[0], $event);
                if ($event->handled) {
                    return;
                }
            }
        }
    }
}
