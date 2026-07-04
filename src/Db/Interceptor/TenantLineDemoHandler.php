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

use Swoolefy\Core\Coroutine\Context as SwooleContext;

class TenantLineDemoHandler implements TenantLineHandlerInterface
{
    /**
     * 获取当前租户ID。
     *
     * 当返回空字符串时，TenantLineInterceptor 会认为当前 SQL 不启用租户过滤。
     * 协程 Context 中可能是 int，此处统一转为 string。
     */
    public function getTenantId(): string
    {
        return (string) SwooleContext::get('tenant_id');
    }

    /**
     * 获取租户字段名。
     *
     * 多数项目默认使用tenant_id。拦截器会用该字段判断数据表是否参与租户隔离，
     * 并生成类似`tenant_id` = :tenant_id的过滤条件。
     *
     * @return string
     */
    public function getTenantIdColumn(): string
    {
        return 'tenant_id';
    }

    /**
     * 判断数据表是否跳过租户隔离的兜底钩子。
     *
     * TenantLineInterceptor会优先从当前连接读取表字段元信息。如果表包含租户字段，
     * 则不忽略并自动拼接租户条件；如果表不包含租户字段，则自动忽略。
     *
     * 只有在无法读取表字段时才会调用该方法，例如表名是动态表达式、连接没有元数据
     * 访问权限，或SQL结构无法可靠映射到普通数据表。
     *
     * @param string $tableName 去除引号后的标准表名。
     * @return bool true表示跳过租户过滤，false表示强制进行租户过滤。
     */
    public function ignoreTable(string $tableName): bool
    {
        return true;
    }
}
