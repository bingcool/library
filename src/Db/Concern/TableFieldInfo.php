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

trait TableFieldInfo
{
    /**
     * @var array
     */
    protected $_schemaInfo  = [];


    /**
     * @return array
     */
    protected function getSchema(): array
    {
        if (empty($this->_schemaInfo)) {
            $schemaInfo = $this->getConnection()->getTableInfo($this->getTable());
            $this->_schemaInfo = $schemaInfo;
        }

        return $this->_schemaInfo;
    }

    /**
     * 获取数据表字段类型信息
     * @return array
     */
    protected function getTableFields(): array
    {
        return $this->getConnection()->getTableFields($this->getTable());
    }

    /**
     * 获取字段详细信息
     * @access public
     * @param string $tableName 数据表名称
     * @return array
     */
    protected function getFields(): array
    {
        $tableName = $this->getConnection()->parseTableName($this->getTable());
        if (empty($tableName)) {
            return [];
        }
        return $this->getConnection()->getFields($tableName);
    }

    /**
     * 获取字段的类型
     *
     * @return array|mixed
     */
    public function getFieldType(?string $field = null)
    {
        $schemaInfo = $this->getSchema();
        if ($field) {
            return $schemaInfo['type'][$field] ?? null;
        }
        return $schemaInfo['type'] ?? [];
    }

    /**
     * 获取字段类型信息
     * @access public
     * @return array
     */
    public function getFieldsBindType(): array
    {
        $fieldType = $this->getFieldType();
        return array_map([$this->connection, 'getFieldBindType'], $fieldType);
    }

    /**
     * 获取字段绑定的类型信息
     * @access public
     * @param string $fieldType 字段名
     * @return int
     */
    protected function getFieldBindType(string $fieldType): int
    {
        return $this->getConnection()->getFieldBindType($fieldType ?: '');
    }

}
