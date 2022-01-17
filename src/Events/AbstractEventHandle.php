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
 * class AbstractEventHandle
 * @package Common\Library\Events
 */
abstract class AbstractEventHandle
{
    abstract public function handle(array $data, AbstractListener $listener);
}
