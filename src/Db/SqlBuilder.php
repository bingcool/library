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

namespace Swoolefy\Library\Db;

/**
 * Class SqlBuilder
 * @package Swoolefy\Library\Db
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
    public static function buildMultiWhere(string $alias, array $conditions, string &$sql, array &$params, string $operator = 'AND')
    {
        foreach ($conditions as $field => $value) {
            self::buildWhere($alias, $field, $value, $sql, $params, $operator);
        }
    }

    /**
     * @param string $alias
     * @param string $field
     * @param mixed $value
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildWhere(string $alias, string $field, $value, string &$sql, array &$params, string $operator = 'AND')
    {
        $prepareField = static::getPrepareField($field);
        if (!is_null($value)) {
            if (is_array($value)) {
                if (count($value) > 0) {
                    if (count($value) > 1) {
                        $prepareParams= self::buildInWhere($value,$params);
                        $sql .= " {$operator} {$alias}.{$field} IN (".implode(',',$prepareParams).")";
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
     * @param string $alias
     * @param string $field
     * @param mixed $value
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildIntWhere(string $alias, string $field, $value, string &$sql, array &$params, string $operator = 'AND')
    {
        $prepareField = static::getPrepareField($field);
        if(is_null($value))
            return ;

        if(is_array($value))
        {
            $count = count($value);
            if( $count ==0)
                return;

            if($count >1)
            {
                $prepareParams= self::buildInWhere($value,$params);
                $sql .= " {$operator} {$alias}.{$field} IN (".implode(',',$prepareParams).")";

                return;
            }

            $value = current($value);
        }

        $sql .= " {$operator} {$alias}.{$field}={$prepareField}";
        $params["{$prepareField}"] = $value;
    }

    /**
     * @param string $alias
     * @param string $field
     * @param $value
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildNotIntWhere(string $alias, string $field, $value, string &$sql, array &$params, string $operator = 'AND')
    {
        $prepareField = static::getPrepareField($field);
        if( is_null($value) )
            return ;

        if( is_array($value) )
        {
            $count = count($value);
            if($count ==0)
                return;

            if($count >1)
            {
                $prepareParams= self::buildInWhere($value,$params);
                $sql .= " {$operator} {$alias}.{$field} NOT IN (".implode(',',$prepareParams).")";

                return;
            }

            $value = current($value);
        }

        $sql .= " {$operator} {$alias}.{$field} !={$prepareField}";
        $params["{$prepareField}"] = $value;
    }

    /**
     * @param string $alias
     * @param string $field
     * @param $value
     * @param string $sql
     * @param array $params
     * @param string $operator
     */
    public static function buildStringWhere(string $alias, string $field, $value, string &$sql, array &$params, string $operator = 'AND')
    {
        $prepareField = static::getPrepareField($field);
        if(is_null($value))
            return ;

        if(is_array($value))
        {
            $count = count($value);
            if($count ==0)
                return;

            if($count >1)
            {
                $prepareParams= self::buildInWhere($value,$params);
                $sql .= " {$operator} {$alias}.{$field} IN (".implode(',',$prepareParams).")";
                return;
            }

            $value = current($value);
        }

        $sql .= " {$operator} {$alias}.{$field}={$prepareField}";
        $params["{$prepareField}"] = $value;
    }

    /**
     * @param string $alias
     * @param string $field
     * @param $startTime
     * @param $endTime
     * @param string $sql
     * @param array $params
     */
    public static function buildDateRange(string $alias, string $field, $startTime, $endTime, string &$sql, array &$params)
    {
        if ($startTime) {
            $sql .= " and {$alias}.{$field} >= :begin_{$field}";
            $params[":begin_{$field}"] = strlen($startTime) == 10 ? $startTime . ' 00:00:00' : $startTime;
        }
        if ($endTime) {
            $sql .= " and {$alias}.{$field} <= :end_{$field}";
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
     */
    public static function buildMinRange(string $alias, string $field, $min, string &$sql, array &$params, bool $include = true)
    {
        if(is_null($min) || $min === '')
        {
            $min = 0;
        }

        if($include)
        {
            $sql .= " and {$alias}.{$field} >= :min_{$field}";
        }else
        {
            $sql .= " and {$alias}.{$field} > :min_{$field}";
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
     */
    public static function buildMaxRange(string $alias, string $field, $max, string &$sql, array &$params, bool $include = true)
    {
        if(is_null($max) || $max === '')
        {
            $max = 0;
        }

        if($include)
        {
            $sql .= " and {$alias}.{$field} <= :max_{$field}";
        }else
        {
            $sql .= " and {$alias}.{$field} < :max_{$field}";
        }

        $params[":max_{$field}"] = $max;
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
        $sql .= " $operator {$alias}.{$field} like {$keyword}";
    }

    /**
     * @param string $alias
     * @param string $field
     * @param string $rank
     * @param string $sql
     * @param $params
     */
    public static function buildOrderBy(string $alias, string $field, string $rank, string &$sql, &$params)
    {
        if(in_array(strtolower($rank),['asc', 'desc']))
        {
            $sql .= " order by {$alias}.{$field} {$rank}";
        }
    }

    /**
     * @param string $alias
     * @param string $field
     * @param string $sql
     * @param $params
     */
    public static function buildGroupBy(string $alias, string $field, string &$sql, &$params)
    {
        $sql .= " group by {$alias}.{$field}";
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
            $key = static::$preparePrefix.'_'.static::$paramCount;
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
        $key = static::$preparePrefix.'_'.$field.'_'.static::$paramCount;
        static::$paramCount++;
        return $key;
    }

    /**
     * @param $table
     * @param $data
     * @return array
     */
    public static function buildInsert(string $table, $data)
    {
        return self::buildMultiInsert($table, [$data]);
    }

    /**
     * @param string $table
     * @param array $dataSet
     * @return array
     */
    public static function buildMultiInsert(string $table, array $dataSet)
    {
        $fields = [];
        $paramsKeys = [];
        $params = [];
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
