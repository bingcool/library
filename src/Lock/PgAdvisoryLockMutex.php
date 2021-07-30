<?php
namespace Common\Library\Lock;

use Common\Library\Db\Pgsql;

/**
 * +----------------------------------------------------------------------
* | Common library of swoole
* +----------------------------------------------------------------------
* | Licensed ( https://opensource.org/licenses/MIT )
* +----------------------------------------------------------------------
* | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
* +----------------------------------------------------------------------
 */

class PgAdvisoryLockMutex extends \malkusch\lock\mutex\PgAdvisoryLockMutex
{
    public function __construct(Pgsql $pgsql, string $name)
    {
        parent::__construct($pgsql->getPdo(), $name);
    }
}