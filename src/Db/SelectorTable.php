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

namespace Common\Library\Db;

use Common\Library\Exception\DbException;

class SelectorTable
{
    public $tableName = '';

    public $aliasName = '';

    private $subSqlFlag = false;

    public function table(string $tableName) {
        $this->tableName = $tableName;
        if (str_ends_with(trim($tableName, ' '), ')')) {
            $this->subSqlFlag = true;
        }
        return $this;
    }

    public function as(string $alias) {
        $this->aliasName = $alias;
        return $this;
    }

    /**
     * 转换带有别名的完整字段
     *
     * @param string $fieldName
     * @return string
     */
    public function C(string $fieldName) {
        if ($this->subSqlFlag && empty($this->aliasName)) {
            throw new DbException("请为{$this->tableName}子句指定别名");
        }
        if ($this->aliasName) {
            return $this->aliasName . '.' . $fieldName;
        } else {
            return $this->tableName . '.' . $fieldName;
        }
    }
}