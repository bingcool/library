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

/**
 * SQL拦截器接口，在PDO预处理SQL之前执行。
 *
 * 实现类可以通过引用改写SQL字符串和绑定参数。新增的运行时参数应放入
 * bind参数中，不要直接拼接到SQL字面量里，这样改写后的SQL仍然可以安全地
 * 使用PDO预处理执行。
 *
 * 可以通过PDOConnection::addSqlInterceptor()注册到单个连接，
 * 也可以通过PDOConnection::addGlobalSqlInterceptor()注册为全局拦截器。
 */
interface SqlInterceptorInterface
{
    /**
     * SQL执行前拦截处理。
     *
     * 所有经过PDOConnection::PDOStatementHandle()的SQL都会调用该方法，
     * 包括query()、execute()、createCommand()、Model持久化、游标查询
     * 和fetchSql预览。
     *
     * $sql和$bindParams使用引用传递：
     * - $sql可用于追加条件、字段、注释、hint等SQL片段。
     * - $bindParams需要在新增占位符时同步追加绑定值。
     *
     * 如果当前拦截器不支持该SQL类型或数据表，应直接返回，不做改写。
     *
     * @param PDOConnection $connection
     * @param string $sql 即将预处理执行的SQL。
     * @param array $bindParams SQL对应的PDO绑定参数。
     * @return void
     */
    public function beforeExecute(PDOConnection $connection, string &$sql, array &$bindParams): void;
}
