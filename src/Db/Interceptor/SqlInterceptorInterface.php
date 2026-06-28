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

namespace Swoolefy\Library\Db\Interceptor;

use Swoolefy\Library\Db\PDOConnection;

interface SqlInterceptorInterface
{
    /**
     * SQL执行前拦截处理.
     *
     * @param PDOConnection $connection
     * @param string $sql
     * @param array $bindParams
     * @return void
     */
    public function beforeExecute(PDOConnection $connection, string &$sql, array &$bindParams): void;
}
