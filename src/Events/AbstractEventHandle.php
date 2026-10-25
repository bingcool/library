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
declare(strict_types=1);

namespace Swoolefy\Library\Events;

/**
 * class AbstractEventHandle
 * @package Swoolefy\Library\Events
 */
abstract class AbstractEventHandle
{
    abstract public function handle(array $data, AbstractListener $listener);
}
