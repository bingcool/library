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
     * @param $alias
     * @param array $conditions
     * @param $sql
     * @param $params
     * @param string $operator
     */
    public static function buildMultiWhere($alias, array $conditions, &$sql, &$params, $operator = 'AND')
    {
        foreach ($conditions as $field => $value) {
            self::buildWhere($alias, $field, $value, $sql, $params, $operator);
        }
    }

    /**
     * @param $alias
     * @param $field
     * @param $value
     * @param $sql
     * @param $params
     * @param string $operator
     */
    public static function buildWhere($alias, $field, $value, &$sql, &$params, $operator = 'AND')
    {
        $prepareField = static::getPrepareField($field);
        if (!is_null($value)) {
            if (is_array($value)) {
                if (count($value) > 0) {
                    if (count($value) > 1) {
                        $prepareParams= self::buildInWhere($value,$params);
                        $sql .= " {$operator} {$alias}{$field} IN (".implode(',',$prepareParams).")";
                        return;
                    } else {
                        $sql .= " {$operator} {$alias}{$field}={$prepareField}";
                        $params["{$prepareField}"] = current($value);
                    }
                }
            } else {
                $sql .= " {$operator} {$alias}{$field}={$prepareField}";
                $params["{$prepareField}"] = $value;
            }
        }
    }

    /**
     * @param $alias
     * @param $field
     * @param $value
     * @param $sql
     * @param $params
     * @param string $operator
     */
    public static function buildIntWhere($alias, $field, $value, &$sql, &$params, $operator = 'AND')
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
                $sql .= " {$operator} {$alias}{$field} IN (".implode(',',$prepareParams).")";

                return;
            }

            $value = current($value);
        }


        $sql .= " {$operator} {$alias}{$field}={$prepareField}";
        $params["{$prepareField}"] = $value;
    }

    /**
     * @param $alias
     * @param $field
     * @param $value
     * @param $sql
     * @param $params
     * @param string $operator
     */
    public static function buildNotIntWhere($alias, $field, $value, &$sql, &$params, $operator = 'AND')
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
                $sql .= " {$operator} {$alias}{$field} NOT IN (".implode(',',$prepareParams).")";

                return;
            }

            $value = current($value);
        }

        $sql .= " {$operator} {$alias}{$field} !={$prepareField}";
        $params["{$prepareField}"] = $value;
    }

    /**
     * @param $alias
     * @param $field
     * @param $value
     * @param $sql
     * @param $params
     * @param string $operator
     */
    public static function buildStringWhere($alias, $field, $value, &$sql, &$params, $operator = 'AND')
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
                $sql .= " {$operator} {$alias}{$field} IN (".implode(',',$prepareParams).")";
                return;
            }

            $value = current($value);
        }

        $sql .= " {$operator} {$alias}{$field}={$prepareField}";
        $params["{$prepareField}"] = $value;
    }

    /**
     * @param $alias
     * @param $field
     * @param $startTime
     * @param $endTime
     * @param $sql
     * @param $params
     */
    public static function buildDateRange($alias, $field, $startTime, $endTime, &$sql, &$params)
    {
        if ($startTime) {
            $sql .= " and {$alias}{$field} >= :begin_{$field}";
            $params[":begin_{$field}"] = strlen($startTime) == 10 ? $startTime . ' 00:00:00' : $startTime;
        }
        if ($endTime) {
            $sql .= " and {$alias}{$field} <= :end_{$field}";
            $params[":end_{$field}"] = strlen($endTime) == 10 ? $endTime . ' 23:59:59' : $endTime;
        }
    }

    /**
     * @param $alias
     * @param $field
     * @param $min
     * @param $sql
     * @param $params
     * @param bool $include
     */
    public static function buildMinRange($alias, $field, $min, &$sql, &$params, bool $include = true)
    {
        if(is_null($min) || $min === '')
        {
            $min = 0;
        }

        if($include)
        {
            $sql .= " and {$alias}{$field} >= :min_{$field}";
        }else
        {
            $sql .= " and {$alias}{$field} > :min_{$field}";
        }

        $params[":min_{$field}"] = $min;
    }

    /**
     * @param $alias
     * @param $field
     * @param $max
     * @param $sql
     * @param $params
     * @param bool $include
     */
    public static function buildMaxRange($alias, $field, $max, &$sql, &$params, bool $include = true)
    {
        if(is_null($max) || $max === '')
        {
            $max = 0;
        }

        if($include)
        {
            $sql .= " and {$alias}{$field} <= :max_{$field}";
        }else
        {
            $sql .= " and {$alias}{$field} < :max_{$field}";
        }

        $params[":max_{$field}"] = $max;
    }

    /**
     * @param $alias
     * @param $field
     * @param $keyword
     * @param $sql
     * @param $params
     * @param string $operator
     */
    public static function buildLike($alias, $field, $keyword, &$sql, &$params, $operator = 'AND')
    {
        $sql .= " $operator {$alias}{$field} like {$keyword}";
    }

    /**
     * @param $alias
     * @param $field
     * @param $rank
     * @param $sql
     * @param $params
     */
    public static function buildOrderBy($alias, $field, $rank, &$sql, &$params)
    {
        if(in_array(strtolower($rank),['asc', 'desc']))
        {
            $sql .= " order by {$alias}{$field} {$rank}";
        }
    }

    /**
     * @param $alias
     * @param $field
     * @param $sql
     * @param $params
     */
    public static function buildGroupBy($alias, $field, &$sql, &$params)
    {
        $sql .= " group by {$alias}{$field}";
    }

    /**
     * @param $values
     * @param $params
     * @return array
     */
    private static function buildInWhere($values, &$params)
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
     * @param $field
     * @return string
     */
    private static function getPrepareField($field)
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
    public static function buildInsert($table, $data)
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
