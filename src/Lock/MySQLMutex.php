<?php
namespace Common\library\Lock;

use Common\Library\Db\Mysql;

/**
 * +----------------------------------------------------------------------
* | Common library of swoole
* +----------------------------------------------------------------------
* | Licensed ( https://opensource.org/licenses/MIT )
* +----------------------------------------------------------------------
* | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
* +----------------------------------------------------------------------
 */

class MySQLMutex extends \malkusch\lock\mutex\MySQLMutex
{
    public function __construct(Mysql $mysql, string $name, int $timeout = 0)
    {
        parent::__construct($mysql->getPdo(), $name, $timeout);
    }
}