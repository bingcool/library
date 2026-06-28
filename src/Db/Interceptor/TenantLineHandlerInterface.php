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

interface TenantLineHandlerInterface
{
    /**
     * 获取当前租户ID.
     *
     * @return mixed
     */
    public function getTenantId();

    /**
     * 获取租户字段名.
     *
     * @return string
     */
    public function getTenantIdColumn(): string;

    /**
     * 判断表是否忽略租户隔离.
     *
     * @param string $tableName
     * @return bool
     */
    public function ignoreTable(string $tableName): bool;
}
