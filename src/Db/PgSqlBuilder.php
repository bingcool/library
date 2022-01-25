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

class PgSqlBuilder extends SqlBuilder
{
    /**
     * 查找in string. eg: where 1=1 and '8' = ANY(string_to_array(some_column,','))
     *
     * @param string $alias
     * @param string $searchField
     * @param $searchValue
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildFindInSet
    (
        string $alias,
        string $searchField,
        $searchValue,
        string &$sql,
        array &$params,
        string $operator = 'AND'
    )
    {
        if (is_numeric($searchValue)) {
            $searchValue = (string)$searchValue;
        }else
        {
            $searchValue = Util::quote($searchValue);
        }

        $sql .= " {$operator} '{$searchValue}' = ANY(string_to_array({$alias}.{$searchField},',')) ";
    }
}