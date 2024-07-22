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

namespace Common\Library\Spl;


class SplDoubleLink
{
    private $next;
    private $pre;

    function hashNext():bool
    {
        return (bool)$this->next;
    }

    function hashPre():bool
    {
        return (bool)$this->pre;
    }

    function next(...$arg):SplDoubleLink
    {
        if(!$this->next){
            $this->next = $this->newInstance(...$arg);
        }
        return $this->next;
    }

    function pre(...$arg):SplDoubleLink
    {
        if(!$this->pre){
            $this->pre = $this->newInstance(...$arg);
        }
        return $this->pre;
    }

    function delPre()
    {
        $this->pre = null;
        return $this;
    }

    function delNext()
    {
        $this->next = null;
        return $this;
    }

    private function newInstance(...$arg):SplDoubleLink
    {
        $ref = new \ReflectionClass(static::class);
        return $ref->newInstanceArgs($arg);
    }

}