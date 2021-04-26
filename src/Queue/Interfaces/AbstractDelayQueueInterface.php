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

namespace Common\Library\Queue\Interfaces;

Abstract class AbstractDelayQueueInterface
{
    abstract public function addItem(int $score, $value, int $delayTime);

    abstract public function push();

    abstract public function rangeByScore($start, $end, array $options = []);

    abstract public function remRangeByScore($start, $end);
}