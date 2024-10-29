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

namespace Common\Library\Db\Concern;

use Common\Library\Db\PDOConnection;
use Common\Library\Exception\DbException;

/**
 * Trait ParseSql
 * @package Common\Library\Db\Concern
 */
trait ParseSql
{

    /**
     * @param array $allowFields
     * @return array
     */
    protected function parseInsertSql(array $allowFields)
    {
        $fields = $columns = $bindParams = [];
        foreach ($allowFields as $field) {
            if (isset($this->_data[$field])) {
                $fields[] = $field;
                $column = ':' . $field;
                $columns[] = $column;
                $bindParams[$column] = $this->_data[$field];
            }
        }
        $fields = implode(',', $fields);
        $columns = implode(',', $columns);
        $sql = "INSERT INTO {$this->getTableName()} ({$fields}) VALUES ({$columns}) ";
        return [$sql, $bindParams];
    }

    /**
     * @return array
     */
    protected function parseFindSqlByPk()
    {
        $pk = $this->getPk();
        if ($this->isSoftDelete()) {
            $deletedAtField = $this->getSoftDeleteField();
            $sql = "SELECT * FROM {$this->getTableName()} WHERE {$pk}=:pk AND {$deletedAtField} IS NULL";
            $bindParams = [
                ':pk' => $this->getPkValue() ?? 0
            ];
        }else {
            $sql = "SELECT * FROM {$this->getTableName()} WHERE {$pk}=:pk";
            $bindParams = [
                ':pk' => $this->getPkValue() ?? 0
            ];
        }

        return [$sql, $bindParams];
    }

    /**
     * @param array $diffData
     * @param array $allowFields
     * @return array
     */
    protected function parseUpdateSql(array $diffData, array $allowFields)
    {
        $setValues = $bindParams = [];
        $pk = $this->getPk();
        foreach ($allowFields as $field) {
            if (isset($diffData[$field])) {
                $column = ':' . $field;
                $setValues[] = $field . '=' . $column;
                $bindParams[$column] = $diffData[$field];
            }

            if(!empty($this->expressionFields)) {
                // expression
                if(array_key_exists('*@'.$field, $this->expressionFields)) {
                    $setValues[] = $field . '=' . $this->expressionFields['*@'.$field];
                }else if(array_key_exists('+@'.$field, $this->expressionFields)) {
                    // inc
                    $setValues[] = $field.'='.$field.'+'.$this->expressionFields['+@'.$field];
                }else if(array_key_exists('-@'.$field, $this->expressionFields)) {
                    // sub
                    $setValues[] = $field.'='.$field.'-'.$this->expressionFields['-@'.$field];
                }
            }
        }
        $this->expressionFields = [];
        $setValueStr = implode(',', $setValues);
        if ($this->isSoftDelete()) {
            $deletedAtField = $this->getSoftDeleteField();
            $sql = "UPDATE {$this->getTableName()} SET {$setValueStr} WHERE {$pk}=:pk AND {$deletedAtField} IS NULL";
        }else {
            $sql = "UPDATE {$this->getTableName()} SET {$setValueStr} WHERE {$pk}=:pk";
        }
        $bindParams[':pk'] = $this->getPkValue() ?? 0;
        return [$sql, $bindParams];
    }

    /**
     * parseDeleteSql
     * @return array
     */
    protected function parseDeleteSql()
    {
        $pk = $this->getPk();
        $pkValue = $this->getPkValue();
        if ($pkValue) {
            $sql = "DELETE FROM {$this->getTableName()} WHERE {$pk}=:pk LIMIT 1";
            $bindParams[':pk'] = $pkValue;
        }
        return [$sql ?? '', $bindParams ?? []];
    }

    /**
     * parseSoftDeleteSql
     * @return array
     */
    protected function parseSoftDeleteSql()
    {
        if ($this->isSoftDelete()) {
            $pk = $this->getPk();
            $pkValue = $this->getPkValue();
            $deletedAtField = $this->getSoftDeleteField();
            $deleteDate = date('Y-m-d H:i:s');
            if ($pkValue) {
                $sql = "UPDATE {$this->getTableName()} SET {$deletedAtField}=:deleteDate WHERE {$pk}=:pk LIMIT 1";
                $bindParams[':pk'] = $pkValue;
                $bindParams[':deleteDate'] = $deleteDate;
            }
            return [$sql ?? '', $bindParams ?? []];
        }
        return [$sql ?? '', $bindParams ?? []];
    }

    /**
     * @param string $where
     * @param array $bindParams
     * @return string
     */
    protected function parseWhereSql(string $where)
    {
        $sql = "SELECT * FROM {$this->getTableName()} WHERE {$where}";
        return $sql;
    }

    /**
     * @param string $where
     * @param array $bindParams
     * @return $this
     */
    public function findOne(string $where, array $bindParams = [])
    {
        if ($this->isSoftDelete()) {
            $where .= ' AND ' . $this->getSoftDeleteField() . ' IS NULL';
        }
        $sql = $this->parseWhereSql($where);
        /**@var PDOConnection $connection */
        $connection = $this->getSlaveConnection();
        if (!is_object($connection)) {
            $connection = $this->getConnection();
        }
        $attributes = $connection->createCommand($sql)->queryOne($bindParams);
        if ($attributes) {
            $pk = $this->getPk();
            if(!isset($attributes[$pk])) {
                $className = get_class($this);
                throw new DbException("{$className} property error, no match table primary key");
            }
            $this->parseOrigin($attributes);
            $this->setIsNew(false);
        } else {
            $this->exists(false);
            $this->setIsNew(true);
        }
        return $this;
    }

    /**
     * 获取一条数据
     *
     * @param array $whereMap
     * @return $this
     */
    public function loadOne(array $whereMap)
    {
        $whereArr = $bindParams = [];
        foreach ($whereMap as $field => $value) {
            $newField = ":{$field}";
            $whereArr[] = "{$field}=$newField";
            $bindParams[$newField] = $value;
        }

        $where = implode(' and ', $whereArr);
        return $this->findOne($where, $bindParams);
    }

    /**
     * @param array $attributes
     */
    protected function parseOrigin(array $attributes = [])
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