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

/**
 * Class SqlBuilder
 * @package Common\Library\Db
 */
class SqlBuilder
{
    static $preparePrefix = ':SW_PREPARE';
    static $paramCount = 0;

    /**
     * @param string $alias
     * @param array $conditions
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildMultiWhere(
        string $alias,
        array $conditions,
        string &$sql,
        array &$params,
        string $operator = 'AND'
    )
    {
        foreach ($conditions as $field => $value) {
            self::buildWhere($alias, $field, $value, $sql, $params, $operator);
        }
    }

    /**
     * field = int|string  or field in (int,int...) or field in ('name1','name2'...)
     * @param string $alias
     * @param string $field
     * @param mixed $value
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildWhere(
        string $alias,
        string $field,
        $value,
        string &$sql,
        array &$params,
        string $operator = 'AND'
    )
    {
        $prepareField = static::getPrepareField($field);
        if (!is_null($value)) {
            if (is_array($value)) {
                if (count($value) > 0) {
                    if (count($value) > 1) {
                        $prepareParams = self::buildInWhere($value, $params);
                        $sql .= " {$operator} {$alias}.{$field} IN (" . implode(',', $prepareParams) . ")";
                        return;
                    } else {
                        $sql .= " {$operator} {$alias}.{$field}={$prepareField}";
                        $params["{$prepareField}"] = current($value);
                    }
                }
            } else {
                $sql .= " {$operator} {$alias}.{$field}={$prepareField}";
                $params["{$prepareField}"] = $value;
            }
        }
    }

    /**
     * field = 数字或者字符串
     * @param string $alias
     * @param string $field
     * @param $value
     * @param string $sql
     * @param array $params
     * @param string $operator
     * @throws \DbException
     */
    public static function buildEqualWhere(
        string $alias,
        string $field,
        $value,
        string &$sql,
        array &$params,
        string $operator = 'AND'
    )
    {
        if (is_array($value) || is_object($value)) {
            throw new DbException('Params item of value must string or int');
        }

        self::buildWhere($alias, $field, $value, $sql, $params, $operator);
    }

    /**
     * 数字整型条件 field = int   or field in (id1,id2...)
     * @param string $alias
     * @param string $field
     * @param mixed $value
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildIntWhere(
        string $alias,
        string $field,
        $value,
        string &$sql,
        array &$params,
        string $operator = 'AND'
    )
    {
        $prepareField = static::getPrepareField($field);
        if (is_null($value))
            return;

        if (is_array($value)) {
            $count = count($value);
            if ($count == 0)
                return;

            if ($count > 1) {
                $prepareParams = self::buildInWhere($value, $params);
                $sql .= " {$operator} {$alias}.{$field} IN (" . implode(',', $prepareParams) . ")";

                return;
            }

            $value = current($value);
        }

        $sql .= " {$operator} {$alias}.{$field}={$prepareField} ";
        $params["{$prepareField}"] = $value;
    }

    /**
     * 数字整型条件 field != int or field not in (id1,id2...)
     * @param string $alias
     * @param string $field
     * @param $value
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildNotIntWhere(
        string $alias,
        string $field,
        $value,
        string &$sql,
        array &$params,
        string $operator = 'AND'
    )
    {
        $prepareField = static::getPrepareField($field);
        if (is_null($value))
            return;

        if (is_array($value)) {
            $count = count($value);
            if ($count == 0)
                return;

            if ($count > 1) {
                $prepareParams = self::buildInWhere($value, $params);
                $sql .= " {$operator} {$alias}.{$field} NOT IN (" . implode(',', $prepareParams) . ")";

                return;
            }

            $value = current($value);
        }

        $sql .= " {$operator} {$alias}.{$field} !={$prepareField} ";
        $params["{$prepareField}"] = $value;
    }

    /**
     * 字符串条件 field = 'name'  or  field not in ('name1','name2'...)
     * @param string $alias
     * @param string $field
     * @param $value
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildStringWhere(
        string $alias,
        string $field,
        $value,
        string &$sql,
        array &$params,
        string $operator = 'AND'
    )
    {
        $prepareField = static::getPrepareField($field);
        if (is_null($value))
            return;

        if (is_array($value)) {
            $count = count($value);
            if ($count == 0)
                return;

            if ($count > 1) {
                $prepareParams = self::buildInWhere($value, $params);
                $sql .= " {$operator} {$alias}.{$field} IN (" . implode(',', $prepareParams) . ")";
                return;
            }

            $value = current($value);
        }

        $sql .= " {$operator} {$alias}.{$field}={$prepareField} ";
        $params["{$prepareField}"] = $value;
    }

    /**
     * 日期范围
     * @param string $alias
     * @param string $field
     * @param $startTime
     * @param $endTime
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildDateRange(
        string $alias,
        string $field,
        $startTime,
        $endTime,
        string &$sql,
        array &$params,
        string $operator = 'AND'
    )
    {
        if ($startTime) {
            $sql .= " {$operator} {$alias}.{$field} >= :begin_{$field} ";
            $params[":begin_{$field}"] = strlen($startTime) == 10 ? $startTime . ' 00:00:00' : $startTime;
        }
        if ($endTime) {
            $sql .= " {$operator} {$alias}.{$field} <= :end_{$field} ";
            $params[":end_{$field}"] = strlen($endTime) == 10 ? $endTime . ' 23:59:59' : $endTime;
        }
    }

    /**
     * 大于等于某个值
     * @param string $alias
     * @param string $field
     * @param $min
     * @param string $sql
     * @param array $params
     * @param bool $include
     * @param string $operator
     */
    public static function buildMinRange(
        string $alias,
        string $field,
        $min,
        string &$sql,
        array &$params,
        bool $include = true,
        string $operator = 'AND'
    )
    {
        if (is_null($min) || $min === '') {
            $min = 0;
        }

        if ($include) {
            $sql .= " {$operator} {$alias}.{$field} >= :min_{$field} ";
        } else {
            $sql .= " {$operator} {$alias}.{$field} > :min_{$field} ";
        }

        $params[":min_{$field}"] = $min;
    }

    /**
     * 小于等于某个值
     * @param string $alias
     * @param string $field
     * @param $max
     * @param string $sql
     * @param array $params
     * @param bool $include
     * @param string $operator
     */
    public static function buildMaxRange(
        string $alias,
        string $field,
        $max,
        string &$sql,
        array &$params,
        bool $include = true,
        string $operator = 'AND'
    )
    {
        if (is_null($max) || $max === '') {
            $max = 0;
        }

        if ($include) {
            $sql .= " {$operator} {$alias}.{$field} <= :max_{$field} ";
        } else {
            $sql .= " {$operator} {$alias}.{$field} < :max_{$field} ";
        }

        $params[":max_{$field}"] = $max;
    }

    /**
     * 在两个值之间
     * @param string $alias
     * @param string $field
     * @param int $min
     * @param int $max
     * @param string $sql
     * @param array $params
     * @param bool $include
     */
    public static function buildBetweenRange(string $alias, string $field, int $min, int $max, string &$sql, array &$params, bool $include = true)
    {
        self::buildMinRange($alias, $field, $min, $sql, $params, $include);
        self::buildMaxRange($alias, $field, $max, $sql, $params, $include);
    }

    /**
     * 组合key where查询 eg: where (`user_id`,`order_amount`,`sku`) in ((10000,105,'mi4'),(10000,123.5,'mi5'));
     *
     * @param string $alias
     * @param array $groupField  ['user_id','order_amount','sku']
     * @param array $groupValue  ['(10000,105,'mi4')', '(10000,123.5,'mi5')' ]
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildGroupFieldWhere(string $alias, array $groupField, array $groupValue, string &$sql, array &$params, string $operator = 'AND')
    {
        $newGroupField = $newGroupValue = [];
        if($alias) {
            foreach($groupField as $field) {
                $newGroupField[] = $alias.'.'.$field;
            }
        }else {
            $newGroupField = $groupField;
        }

        foreach ($groupValue as $value) {
            $newGroupValue[] = Util::quote($value);
        }

        $groupFieldStr = '('.implode(',', $newGroupField).')';
        $sql .= " {$operator} {$groupFieldStr} IN (" . implode(',', $groupValue) . ")";
    }

    /**
     * @param string $alias
     * @param string $field
     * @param string $keyword
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildLike(string $alias, string $field, string $keyword, string &$sql, &$params, string $operator = 'AND')
    {
        $paramCount = ++static::$paramCount;
        $preLikeField = ":like{$paramCount}_{$field}";
        $sql .= " {$operator} {$alias}.{$field} LIKE $preLikeField ";
        $params[$preLikeField] = $keyword;
    }


    /**
     * buildGroupBy('a', ['wid','product_id'], $sql, $params)
     * @param string $alias
     * @param array $groupFields
     * @param string $sql
     * @param $params
     */
    public static function buildGroupBy
    (
        string $alias,
        array $groupFields,
        string &$sql,
        &$params
    )
    {
        $groupFieldItem = [];
        foreach ($groupFields as $field) {
            if (strpos($field, '.') === false) {
                $groupFieldItem[] = "{$alias}.{$field}";
            } else {
                $groupFieldItem[] = "{$field}";
            }
        }

        if ($groupFieldItem) {
            $groupFieldStr = implode(',', $groupFieldItem);
            $groupFieldStr = Util::quote($groupFieldStr);
            $sql .= " GROUP BY {$groupFieldStr} ";
        }
    }


    /**
     * having 必须跟在group by 之后
     *
     * @param string $alias
     * @param string $having
     * @param string $sql
     * @param $params
     */
    public static function buildHaving
    (
        string $alias,
        string $having,
        string &$sql,
        &$params
    )
    {
        $having = Util::quote($having);
        $sql .= " HAVING {$having} ";
    }

    /**
     *buildOrderBy('a', ['sex'=>'asc, 'score'=>'desc'], $sql, $params)
     *
     * @param string $alias
     * @param array $orderFieldRankMap
     * @param string $sql
     * @param $params
     */
    public static function buildOrderBy
    (
        string $alias,
        array $orderFieldRankMap,
        string &$sql,
        &$params
    )
    {
        $sortField = [];
        foreach ($orderFieldRankMap as $field => $rank) {
            if (in_array(strtolower($rank), ['asc', 'desc'])) {
                if (strpos($field, '.') === false) {
                    $sortField[] = "{$alias}.{$field} {$rank}";
                } else {
                    $sortField[] = "{$field} {$rank}";
                }
            }
        }

        if ($sortField) {
            $sortFieldStr = implode(',', $sortField);
            $sortFieldStr = Util::quote($sortFieldStr);
            $sql .= " ORDER BY {$sortFieldStr} ";
        }

    }


    /**
     * 分页,order by 之后
     *
     * @param string $alias
     * @param int $offset
     * @param int $size
     * @param string $sql
     * @param array $params
     */
    public static function buildLimit
    (
        string $alias,
        int $offset,
        int $size,
        string &$sql,
        &$params
    )
    {
        $sql .= " LIMIT {$offset},{$size} ";
    }

    /**
     * @param array $values
     * @param array $params
     * @return array
     */
    private static function buildInWhere(array $values, array &$params)
    {
        $prepareParams = [];
        foreach ($values as $item) {
            $key = static::$preparePrefix . '_' . static::$paramCount;
            $prepareParams[] = $key;
            $params[$key] = $item;
            static::$paramCount++;
        }
        return $prepareParams;
    }

    /**
     * @param string $field
     * @return string
     */
    private static function getPrepareField(string $field)
    {
        $key = static::$preparePrefix . '_' . $field . '_' . static::$paramCount;
        static::$paramCount++;
        return $key;
    }


    /**
     * 查找in string. eg: where FIND_IN_SET('aa@gmail.com', emails);
     *
     * @param string $alias
     * @param string $searchField
     * @param $searchValue
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildFindInSet(
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
        }else {
            $searchValue = Util::quote($searchValue);
        }

        $sql .= " {$operator} find_in_set('{$searchValue}', {$alias}.{$searchField}) ";
    }

    /**
     * 表达式
     *
     * @param string $alias
     * @param string $field
     * @param string $expression
     * @param string $sql
     * @param array $params
     */
    public static function buildExpression(
        string $alias,
        string $field,
        string $expression,
        string &$sql,
        array &$params
    )
    {
        $sql .= " {$alias}.{$field}={$expression} ";
    }

    /**
     * @param $table
     * @param $data
     * @return array
     */
    public static function buildInsertSql(string $table, array $data)
    {
        return self::buildBatchInsertSql($table, [$data]);
    }

    /**
     * @param string $table
     * @param array $dataSet
     * @return array
     */
    public static function buildBatchInsertSql(string $table, array $dataSet)
    {
        $fields = $paramsKeys = $params = [];
        foreach ($dataSet as $index => $data) {
            foreach ($data as $k => $v) {
                $fields[$k] = $k;
                $paramsKeys[$index][] = $paramKey = ":{$k}_{$index}";
                $params[$paramKey] = $v;
            }
            $paramsKeys[$index] = "(" . implode(',', $paramsKeys[$index]) . ")";
        }

        $sql = "INSERT INTO {$table} (" . implode(',', $fields) . ") VALUES " . implode(',', $paramsKeys);

        return [$sql, $params];
    }
}
