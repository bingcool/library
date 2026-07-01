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

namespace Swoolefy\Library\Db\Concern;

use Swoolefy\Library\Db\PDOConnection;
use Swoolefy\Library\Db\Query;

/**
 * Model 查询构建阶段自动追加 tenant_id 条件，供子查询与常规 Query 使用。
 * 原生 SQL / EXISTS / JOIN 等仍由 TenantLineInterceptor 兜底。
 */
trait TenantScope
{
    public $__enableTenantScope = true;

    public function isTenantScope(): bool
    {
        return property_exists($this, '__enableTenantScope') && $this->__enableTenantScope === true;
    }

    /**
     * 为 Model 关联的 Query 追加租户过滤（表含 tenant 字段且上下文存在租户 ID 时）。
     */
    public function applyTenantScope(Query $query): void
    {
        if (!$this->isTenantScope()) {
            return;
        }

        $handler = TenantScopeContext::getHandler();
        if ($handler === null) {
            return;
        }

        $tenantId = $handler->getTenantId();
        if ($tenantId === null || $tenantId === '') {
            return;
        }

        $table = $this->getTable();
        if ($table === '') {
            return;
        }

        $tableName = $this->getConnection()->parseTableName($table);
        if (!is_string($tableName) || $tableName === '') {
            return;
        }

        if (!TenantTableMetadata::tableHasTenantColumn($this->getConnection(), $tableName, $handler)) {
            return;
        }

        $column = trim($handler->getTenantIdColumn(), '`"[] ');
        $query->where($table . '.' . $column, '=', $tenantId);
    }

    /**
     * 构建不带租户 Scope 的 Query（管理端跨租户查询等场景）。
     */
    public static function withoutTenantScope(?PDOConnection $connection = null): Query
    {
        $model = new static();
        $model->__enableTenantScope = false;

        if (!is_object($connection)) {
            $connection = $model->getConnection();
        }

        if (method_exists($connection, 'getObject')) {
            $query = (new Query($connection->getObject()))->table($model->getTable());
        } else {
            $query = (new Query($connection))->table($model->getTable());
        }

        $query->setModel($model);

        return $query;
    }
}
