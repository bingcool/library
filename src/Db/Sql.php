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

namespace Swoolefy\Library\Db;

class Sql
{
    /**
     * 构建表对象
     *
     * @param string $tableName
     * @return SelectorTable
     */
    public static function table(string $tableName)
    {
        $tableSelector = new SelectorTable();
        $tableSelector->table($tableName);
        return $tableSelector;
    }

    /**
     * @param string $fieldName1
     * @param string $fieldName2
     * @return string
     */
    public static function on(string $fieldName1, string $fieldName2)
    {
        return join(" = ", [$fieldName1, $fieldName2]);
    }

    /**
     * @param string $fieldName1
     * @param string $fieldName2
     * @return string
     */
    public static function andOn(string ...$conditions)
    {
        return trim(join(" AND ", $conditions),'AND');
    }

    /**
     * @param string $sql
     * @return string
     */
    public static function buildSubSql(string $sql)
    {
        $sql = trim($sql, " ");
        if (str_starts_with($sql, "(") && str_ends_with($sql, ")")) {
            return $sql;
        }else {
            return '( ' . $sql . ' )';
        }
    }
}