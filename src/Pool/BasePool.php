<?php
/**
+----------------------------------------------------------------------
| Common library of swoole
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
 */

namespace Common\Library\Pool;

class BasePool extends \Swoole\ConnectionPool
{
    /**
     * @return int
     */
    public function getSize()
    {
        return $this->num;
    }

}