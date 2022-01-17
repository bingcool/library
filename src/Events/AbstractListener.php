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
 * class AbstractListener
 * @package Common\Library\Events
 */

abstract class AbstractListener
{

    /**
     * @var array
     */
    protected $data = [];

    /**
     * @var array
     */
    protected $result = [];

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
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param $class
     * @param $return
     */
    public function setResult($class, $return)
    {
        $this->result[$class] = $return;
    }

    /**
     * @param string|null $className
     */
    public function getResult(?string $className = null)
    {
        return $className ?  ($this->result[$className] ?? null) : $this->result;
    }
}