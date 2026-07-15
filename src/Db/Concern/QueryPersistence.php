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

use Swoolefy\Library\Db\Query;
use Swoolefy\Library\Db\Raw;
use Swoolefy\Library\Exception\DbException;

/**
 * Model 持久化统一走 Query Builder，确保 SQL 拦截器（如多租户）对 save/update/delete 生效。
 */
trait QueryPersistence
{
    /**
     * 构建持久化 Query（写操作用主库，读操作优先从库）。
     */
    protected function newPersistenceQuery(bool $forRead = false): Query
    {
        $connection = $forRead ? $this->getSlaveConnection() : $this->getConnection();
        if (!is_object($connection)) {
            $connection = $this->getConnection();
        }

        if (method_exists($connection, 'getObject')) {
            $query = (new Query($connection->getObject()))->table($this->getTable());
        } else {
            $query = (new Query($connection))->table($this->getTable());
        }

        $query->setModel($this);

        return $query;
    }

    /**
     * 将未知方法调用代理到当前模型的 Query 实例。
     */
    protected function invokeQueryMethod(string $method, array $arguments)
    {
        $query = $this->getQuery();

        return $query->{$method}(...$arguments);
    }

    /**
     * 从模型数据中提取允许写入的字段。
     */
    protected function collectInsertData(array $allowFields): array
    {
        $data = [];
        foreach ($allowFields as $field) {
            if (array_key_exists($field, $this->_data)) {
                $data[$field] = $this->_data[$field];
            }
        }

        return $data;
    }

    /**
     * 合并 diff 数据与表达式字段（exp / inc / sub）。
     */
    protected function buildUpdateData(array $diffData, array $allowFields): array
    {
        $data = [];
        foreach ($allowFields as $field) {
            if (array_key_exists($field, $diffData)) {
                $data[$field] = $diffData[$field];
            }
        }

        if (!empty($this->expressionFields)) {
            foreach ($allowFields as $field) {
                if (array_key_exists('*@' . $field, $this->expressionFields)) {
                    $data[$field] = new Raw($this->expressionFields['*@' . $field]);
                } elseif (array_key_exists('+@' . $field, $this->expressionFields)) {
                    $data[$field] = ['INC', $this->expressionFields['+@' . $field]];
                } elseif (array_key_exists('-@' . $field, $this->expressionFields)) {
                    $data[$field] = ['DEC', $this->expressionFields['-@' . $field]];
                }
            }
            $this->expressionFields = [];
        }

        return $data;
    }

    /**
     * 追加主键、乐观锁及软删除可见性条件。
     */
    protected function applyPersistenceWhere(Query $query, bool $withSoftDeleteScope = false): void
    {
        $pk = $this->getPk();
        $query->where($pk, '=', $this->getPkValue() ?? 0);

        if (!empty($this->lockShareWhereFieldValues)) {
            foreach ($this->lockShareWhereFieldValues as $field => $value) {
                $query->where($field, '=', $value);
            }
            $this->lockShareWhereFieldValues = [];
        }

        if ($withSoftDeleteScope && $this->isSoftDelete()) {
            $query->whereNull($this->getSoftDeleteField());
        }
    }

    /**
     * 通过 Query 执行 INSERT。
     */
    protected function executePersistenceInsert(array $allowFields): int
    {
        $data = $this->collectInsertData($allowFields);
        if (empty($data)) {
            throw new DbException('insert data empty');
        }

        $query = $this->newPersistenceQuery();
        $query->insert($data, false);

        return (int) $this->getConnection()->getNumRows();
    }

    /**
     * 通过 Query 执行 UPDATE。
     */
    protected function executePersistenceUpdate(array $diffData, array $allowFields): int
    {
        $data = $this->buildUpdateData($diffData, $allowFields);
        if (empty($data)) {
            return 0;
        }

        $query = $this->newPersistenceQuery();
        $this->applyPersistenceWhere($query, true);
        $query->update($data);

        return (int) $this->getConnection()->getNumRows();
    }

    /**
     * 通过 Query 执行物理 DELETE。
     */
    protected function executePersistenceDelete(): int
    {
        $pkValue = $this->getPkValue();
        if (!$pkValue) {
            return 0;
        }

        $query = $this->newPersistenceQuery();
        $query->where($this->getPk(), '=', $pkValue);

        return $query->delete(true);
    }

    /**
     * 通过 Query 执行软删除（更新 deleted_at）。
     */
    protected function executePersistenceSoftDelete(): int
    {
        if (!$this->isSoftDelete()) {
            return 0;
        }

        $pkValue = $this->getPkValue();
        if (!$pkValue) {
            return 0;
        }

        $query = $this->newPersistenceQuery();
        $query->where($this->getPk(), '=', $pkValue);

        return $query->update([
            $this->getSoftDeleteField() => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 按主键重新加载行数据（INSERT 后刷新属性）。
     */
    protected function reloadByPrimaryKey(): ?array
    {
        $query = $this->newPersistenceQuery(true);
        $this->applyTenantScope($query);
        $query->where($this->getPk(), '=', $this->getPkValue() ?? 0);
        if ($this->isSoftDelete()) {
            $query->whereNull($this->getSoftDeleteField());
        }

        $rows = $query->limit(1)->find();

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        return $rows[0] ?? null;
    }

    /**
     * 按自定义 WHERE 查询单条记录。
     */
    public function findOne(string $where, array $bindParams = [])
    {
        $query = $this->newPersistenceQuery(true);
        $this->applyTenantScope($query);
        $query->whereRaw($where, $bindParams);

        return $this->findOneByQuery($query);
    }

    /**
     * 按字段等值 map 查询单条记录。
     */
    public function loadOne(array $whereMap): ?static
    {
        $query = $this->newPersistenceQuery(true);
        $this->applyTenantScope($query);
        foreach ($whereMap as $field => $value) {
            $query->where($field, '=', $value);
        }

        return $this->findOneByQuery($query);
    }

    /**
     * 在已有 Query 上完成单条查询并填充模型。
     *
     * @internal
     */
    protected function findOneByQuery(Query $query): ?static
    {
        if ($this->isSoftDelete()) {
            $query->whereNull($this->getSoftDeleteField());
        }

        $rows = $query->limit(1)->find();
        $attributes = (is_array($rows) && isset($rows[0])) ? $rows[0] : null;

        if ($attributes) {
            $pk = $this->getPk();
            if (!isset($attributes[$pk])) {
                $className = get_class($this);
                throw new DbException("{$className} property error, no match table primary key");
            }
            $this->parseOrigin($attributes);
            $this->setIsNew(false);

            return $this;
        }

        $this->exists(false);
        $this->setIsNew(true);

        return null;
    }

    /**
     * 将查询结果写入模型当前数据与原始快照。
     */
    public function parseOrigin(array $attributes = [])
    {
        if ($attributes) {
            foreach ($attributes as $field => $value) {
                $this->_data[$field] = $value;
                $this->_origin[$field] = $value;
            }
            $this->exists(true);
        }
    }
}
