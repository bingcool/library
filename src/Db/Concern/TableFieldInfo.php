<?php
/**
+----------------------------------------------------------------------
| Common library of swoole
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
 */

namespace Common\Library\Db\Concern;

trait TableFieldInfo
{

    /**
     * @return array
     */
    protected function getSchemaInfo(): array
    {
        if(empty($this->_schemaInfo))
        {
            $table = $this->table ? $this->table . $this->_suffix : $this->table;
            $schemaInfo = $this->getConnection()->getSchemaInfo($table);
            $this->_schemaInfo = $schemaInfo;
        }

        return $this->_schemaInfo;
    }

    /**
     * 获取数据表字段类型信息
     * @access public
     * @param string $tableName 数据表名
     * @return array
     */
    protected function getTableFields(): array
    {
        return $this->getConnection()->getTableFieldsInfo($this->getTableName());
    }

    /**
     * 获取字段详细信息
     * @access public
     * @param string $tableName 数据表名称
     * @return array
     */
    protected function getFields(): array
    {
        return $this->getConnection()->getFields($this->getTableName());
    }

    /**
     * 获取字段的类型
     *
     * @return array|mixed
     */
    protected function getFieldType(?string $field = null)
    {
        $schemaInfo = $this->getSchemaInfo();
        if($field) {
            return $schemaInfo['type'][$field] ?? null;
        }
        return $schemaInfo['type'] ?? [];
    }

    /**
     * 获取字段绑定的类型信息
     * @access public
     * @param string $field 字段名
     * @return int
     */
    protected function getFieldBindType(string $field): int
    {
        $fieldType = $this->getFieldType($field) ?? null;
        return $this->getConnection()->getFieldBindType($fieldType ?: '');
    }

}
