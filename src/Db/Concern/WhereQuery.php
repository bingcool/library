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

use Closure;
use Common\Library\Db\BaseQuery;
use Common\Library\Db\Raw;

trait WhereQuery
{
    /**
     * 指定AND查询条件
     * @access public
     * @param mixed $field     查询字段
     * @param mixed $op        查询表达式
     * @param mixed $condition 查询条件
     * @return $this
     */
    public function where($field, $op = null, $condition = null)
    {
        if ($field instanceof $this) {
            $this->parseQueryWhere($field);
            return $this;
        } elseif (true === $field || 1 === $field) {
            $this->options['where']['AND'][] = true;
            return $this;
        }

        $param = func_get_args();
        array_shift($param);
        return $this->parseWhereExp('AND', $field, $op, $condition, $param);
    }

    /**
     * 解析Query对象查询条件
     * @access public
     * @param BaseQuery $query 查询对象
     * @return void
     */
    protected function parseQueryWhere(BaseQuery $query): void
    {
        $this->options['where'] = $query->getOptions('where') ?? [];

        if ($query->getOptions('via')) {
            $via = $query->getOptions('via');
            foreach ($this->options['where'] as $logic => &$where) {
                foreach ($where as $key => &$val) {
                    if (is_array($val) && !strpos($val[0], '.')) {
                        $val[0] = $via . '.' . $val[0];
                    }
                }
            }
        }

        $this->bind($query->getBind(false));
    }

    /**
     * 指定OR查询条件
     * @access public
     * @param mixed $field     查询字段
     * @param mixed $op        查询表达式
     * @param mixed $condition 查询条件
     * @return $this
     */
    public function whereOr($field, $op = null, $condition = null)
    {
        $param = func_get_args();
        array_shift($param);
        return $this->parseWhereExp('OR', $field, $op, $condition, $param);
    }

    /**
     * 指定XOR查询条件
     * @access public
     * @param mixed $field     查询字段
     * @param mixed $op        查询表达式
     * @param mixed $condition 查询条件
     * @return $this
     */
    public function whereXor($field, $op = null, $condition = null)
    {
        $param = func_get_args();
        array_shift($param);
        return $this->parseWhereExp('XOR', $field, $op, $condition, $param);
    }

    /**
     * 指定Null查询条件
     * @access public
     * @param mixed  $field 查询字段
     * @param string $logic 查询逻辑 and or xor
     * @return $this
     */
    public function whereNull(string $field, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'NULL', null, [], true);
    }

    /**
     * 指定NotNull查询条件
     * @access public
     * @param mixed  $field 查询字段
     * @param string $logic 查询逻辑 and or xor
     * @return $this
     */
    public function whereNotNull(string $field, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'NOTNULL', null, [], true);
    }

    /**
     * 指定Exists查询条件
     * @access public
     * @param mixed  $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereExists($condition, string $logic = 'AND')
    {
        if (is_string($condition)) {
            $condition = new Raw($condition);
        }

        $this->options['where'][strtoupper($logic)][] = ['', 'EXISTS', $condition];
        return $this;
    }

    /**
     * 指定NotExists查询条件
     * @access public
     * @param mixed  $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereNotExists($condition, string $logic = 'AND')
    {
        if (is_string($condition)) {
            $condition = new Raw($condition);
        }

        $this->options['where'][strtoupper($logic)][] = ['', 'NOT EXISTS', $condition];
        return $this;
    }

    /**
     * 指定In查询条件
     * @access public
     * @param mixed  $field     查询字段
     * @param mixed  $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereIn(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'IN', $condition, [], true);
    }

    /**
     * 指定NotIn查询条件
     * @access public
     * @param mixed  $field     查询字段
     * @param mixed  $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereNotIn(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'NOT IN', $condition, [], true);
    }

    /**
     * 指定Like查询条件
     * @access public
     * @param mixed  $field     查询字段
     * @param mixed  $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereLike(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'LIKE', $condition, [], true);
    }

    /**
     * 指定NotLike查询条件
     * @access public
     * @param mixed  $field     查询字段
     * @param mixed  $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereNotLike(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'NOT LIKE', $condition, [], true);
    }

    /**
     * 指定Between查询条件
     * @access public
     * @param mixed  $field     查询字段
     * @param mixed  $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereBetween(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'BETWEEN', $condition, [], true);
    }

    /**
     * 指定NotBetween查询条件
     * @access public
     * @param mixed  $field     查询字段
     * @param mixed  $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereNotBetween(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'NOT BETWEEN', $condition, [], true);
    }

    /**
     * 指定FIND_IN_SET查询条件
     * @access public
     * @param mixed  $field     查询字段
     * @param mixed  $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereFindInSet(string $field, $condition, string $logic = 'AND')
    {
        return $this->parseWhereExp($logic, $field, 'FIND IN SET', $condition, [], true);
    }

    /**
     * 指定json_contains查询条件.
     *
     * @param mixed  $field     查询字段
     * @param mixed  $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     *
     * @return $this
     */
    public function whereJsonContains(string $field, $condition, string $logic = 'AND')
    {
        $type = $this->getConnection()->getConfig('type');
        $type = strtolower($type);
        if ($type == 'pgsql') {
            return $this->wherePgJsonContains($field, $condition, $logic);
        }else if ($type == 'mysql') {
            return $this->whereMysqlJsonContains($field, $condition, $logic);
        }else if ($type == 'sqlite') {
            return $this->whereSqliteJsonContains($field, $condition, $logic);
        }else if ($type == 'oracle') {
            return $this->whereOracleJsonContains($field, $condition, $logic);
        }
    }

    /**
     * mysql json 查询条件
     *
     * @param string $field
     * @param $condition
     * @param string $logic
     * @return BaseQuery
     */
    protected function whereMysqlJsonContains(string $field, $condition, string $logic = 'AND')
    {
        /**
         * 字段expend_data是关联数组，例如：
         * expend_data={"name": "swoolefy","sex"}
         * 使用：whereJsonContains('expend_data->name', "swoolefy")
         * 最终的sql: json_contains(json_extract(expend_data,'$.name'),'"swoolefy"')
         *
         * 字段expend_data直接存贮的是索引数组，要注意数据存贮类型，如果是整型的，查询值必须是整型，字符串的查询值也必须是字符串，例如：
         * expend_data=[123,456,789]
         * 使用：whereJsonContains('expend_data',123)
         * 最终的sql: json_contains(expend_data,'123')
         *
         * 字段expend_data直接存贮的关联数组，数组里面的phone字段是索引数组,要注意数据存贮类型，如果是整型的，查询值必须是整型，字符串的查询值也必须是字符串，例如：
         * expend_data={"name": "swoolefy", "phone": ['12346543', '123456']}
         * 使用：whereJsonContains('expend_data->phone', '123456')
         * 最终的sql:  json_contains(json_extract(expend_data,'$.phone'),'"123456"')
         *
         * 字段expend_data直接存贮的关联数组，数组里面的address字段是个二维数组，查询address数组里面的add字段的值，例如：
         * expend_data={"name": "swoolefy", "address": [{"add": "shenzhen"}, {"add": "guangzhou"}]}
         * 使用：whereJsonContains('expend_data->address', ['add' => 'shenzhen'])
         * 最终的sql:  json_contains(json_extract(expend_data,'$.address'),'{"add":"shenzhen"}')
         *
         */

        if (str_contains($field, '->')) {
            [$field1, $field2] = explode('->', $field);
            $field             = 'json_extract(' . $field1 . ',\'$.' . $field2 . '\')';
        }
        // 数据表json有些数据可能是数字，但是使用字符串来表示的，eg: ['111','222','333'],这是需要兼容处理
        if (is_numeric($condition)) {
            $value1 = '"' . $condition . '"';
            $value2 = (int)$condition;
            return $this->whereRaw('json_contains(' . $field . ','.'\''.$value1.'\''.') or json_contains(' . $field . ','.'\''.$value2.'\''.')');
        }else {
            if (is_array($condition)) {
                $value = json_encode($condition,JSON_UNESCAPED_UNICODE);
            }else {
                if(str_contains($condition,'{')) {
                    $valueArr = json_decode($condition,true);
                    if (!is_null($valueArr)) {
                        $value = $condition;
                    }else {
                        $value = '"' . $condition . '"';
                    }
                }else {
                    $value = '"' . $condition . '"';
                }
            }
            return $this->whereRaw('json_contains(' . $field . ','.'\''.$value.'\''.')');
        }
    }

    /**
     * pgsql json 查询条件
     *
     * @param string $logic
     * @param string $field
     * @param string $operator
     * @param $condition
     * @param array $bind
     * @param bool $strict
     * @return $this
     */
    protected function wherePgJsonContains(string $field, $condition, string $logic = 'AND')
    {
        /**
         *
         * 字段expend_data是关联数组，查询某个字段的某个值：
         * expend_data ='{"name": "xiaomi", "phone": 123456789}',
         * 使用：whereJsonContains(expend_data->>name, 'xiaoming')
         * 最终的sql: where expend_data @> '{"name":"xiaomi"}'
         *
         * 字段expend_data是索引数组，查询某个值：
         * expend_data =[1222, 345, 567, 81],
         * 使用：wherePgJsonContains('expend_data',['1222'])
         * 最终的sql: WHERE( expend_data @> '["1222"]' or expend_data @> '[1222]' )
         *
         * 字段expend_data关联数组里面的phone是一维数组，查询某个值：
         * expend_data ={"name": "xiaomi", "phone": [123456789, 123456]}
         * 使用：whereJsonContains('expend_data->>phone',[123456789])
         * 最终的sql: WHERE  ( expend_data @> '{"phone":[123456789]}' )
         *
         * 字段expend_data关联数组的address里面是二维关联数组，查询某个值：
         * expend_data ={"name": "xiaomi","address": [{"add1": "深圳"},{"add1": "广州"}]}
         * 使用：whereJsonContains('expend_data->>address',[['add1' => '深圳']])
         * 最终的sql: WHERE  ( expend_data @> '{"address":[{"add1":"深圳"}]}' )
         */

        if (str_contains($field, '->>')) {
            [$field1, $field2] = explode('->>', $field);
            $field = $field1;
            $condition = [
                $field2 => $condition,
            ];
        }

        if (is_array($condition)) {
            $condition1 = [];
            foreach ($condition as $k=>$v) {
                if (is_numeric($v) && is_string($v)) {
                    $condition1[] = (int)$v;
                }
            }
            $value1 = json_encode($condition, JSON_UNESCAPED_UNICODE);
            if (!empty($condition1)) {
                $value2 = json_encode($condition1, JSON_UNESCAPED_UNICODE);
                return $this->whereRaw("{$field} @> '$value1' or {$field} @> '$value2' ");
            }else {
                return $this->whereRaw("{$field} @> '$value1'");
            }
        }else {
            if (is_numeric($condition) && is_string($condition)) {
                $value1 = $condition;
                $value2 = (int)$condition;
                return $this->whereRaw("{$field} @> '$value1' or {$field} @> $value2 ");
            }else {
                $value = $condition;
                return $this->whereRaw("{$field} @> '$value'");
            }
        }
    }

    protected function whereSqliteJsonContains(string $field, $condition, string $logic = 'AND')
    {
        throw new \Exception('sqlite json_contains not support, please use whereRaw() instead');
    }

    protected function whereOrcaleJsonContains(string $field, $condition, string $logic = 'AND')
    {
        throw new \Exception('sqlite json_contains not support, please use whereRaw() instead');
    }

    public function whereOrJsonContains(string $field, $condition)
    {
        return $this->whereJsonContains($field, $condition, 'OR');
    }

    /**
     * 比较两个字段
     * @access public
     * @param string $field1   查询字段
     * @param string $operator 比较操作符
     * @param string $field2   比较字段
     * @param string $logic    查询逻辑 and or xor
     * @return $this
     */
    public function whereColumn(string $field1, string $operator, string $field2 = null, string $logic = 'AND')
    {
        if (is_null($field2)) {
            $field2   = $operator;
            $operator = '=';
        }

        return $this->parseWhereExp($logic, $field1, 'COLUMN', [$operator, $field2], [], true);
    }

    /**
     * 设置软删除字段及条件
     * @access public
     * @param string $field     软删除字段
     * @param mixed  $condition 查询条件
     * @return $this
     */
    public function useSoftDelete(string $field, $condition = null)
    {
        if ($field) {
            $this->options['soft_delete'] = [$field, $condition];
        }

        return $this;
    }

    /**
     * 指定Exp查询条件
     * @access public
     * @param mixed  $field 查询字段
     * @param string $where 查询条件
     * @param array  $bind  参数绑定
     * @param string $logic 查询逻辑 and or xor
     * @return $this
     */
    public function whereExp(string $field, string $where, array $bind = [], string $logic = 'AND')
    {
        $this->options['where'][$logic][] = [$field, 'EXP', new Raw($where, $bind)];

        return $this;
    }

    /**
     * 指定字段Raw查询
     * @access public
     * @param string $field     查询字段表达式
     * @param mixed  $op        查询表达式
     * @param string $condition 查询条件
     * @param string $logic     查询逻辑 and or xor
     * @return $this
     */
    public function whereFieldRaw(string $field, $op, $condition = null, string $logic = 'AND')
    {
        if (is_null($condition)) {
            $condition = $op;
            $op        = '=';
        }

        $this->options['where'][$logic][] = [new Raw($field), $op, $condition];
        return $this;
    }

    /**
     * 指定表达式查询条件
     * @access public
     * @param string $where 查询条件
     * @param array  $bind  参数绑定
     * @param string $logic 查询逻辑 and or xor
     * @return $this
     */
    public function whereRaw(string $where, array $bind = [], string $logic = 'AND')
    {
        $this->options['where'][$logic][] = new Raw($where, $bind);

        return $this;
    }

    /**
     * 指定表达式查询条件 OR
     * @access public
     * @param string $where 查询条件
     * @param array  $bind  参数绑定
     * @return $this
     */
    public function whereOrRaw(string $where, array $bind = [])
    {
        return $this->whereRaw($where, $bind, 'OR');
    }

    /**
     * 分析查询表达式
     * @access protected
     * @param string $logic     查询逻辑 and or xor
     * @param mixed  $field     查询字段
     * @param mixed  $op        查询表达式
     * @param mixed  $condition 查询条件
     * @param array  $param     查询参数
     * @param bool   $strict    严格模式
     * @return $this
     */
    protected function parseWhereExp(string $logic, $field, $op, $condition, array $param = [], bool $strict = false)
    {
        $logic = strtoupper($logic);

        if (is_string($field) && !empty($this->options['via']) && false === strpos($field, '.')) {
            $field = $this->options['via'] . '.' . $field;
        }

        if ($strict) {
            // 使用严格模式查询
            if ('=' == $op) {
                $where = $this->whereEq($field, $condition);
            } else {
                $where = [$field, $op, $condition, $logic];
            }
        } elseif (is_array($field)) {
            // 解析数组批量查询
            return $this->parseArrayWhereItems($field, $logic);
        } elseif ($field instanceof Closure) {
            $where = $field;
        } elseif (is_string($field)) {
            if ($condition instanceof Raw) {

            } elseif (preg_match('/[,=\<\'\"\(\s]/', $field)) {
                return $this->whereRaw($field, is_array($op) ? $op : [], $logic);
            } elseif (is_string($op) && strtolower($op) == 'exp' && !is_null($condition)) {
                $bind = isset($param[2]) && is_array($param[2]) ? $param[2] : [];
                return $this->whereExp($field, $condition, $bind, $logic);
            }

            $where = $this->parseWhereItem($logic, $field, $op, $condition, $param);
        }

        if (!empty($where)) {
            $this->options['where'][$logic][] = $where;
        }

        return $this;
    }

    /**
     * 分析查询表达式
     * @access protected
     * @param string $logic     查询逻辑 and or xor
     * @param mixed  $field     查询字段
     * @param mixed  $op        查询表达式
     * @param mixed  $condition 查询条件
     * @param array  $param     查询参数
     * @return array
     */
    protected function parseWhereItem(string $logic, $field, $op, $condition, array $param = []): array
    {
        if (is_array($op)) {
            // 同一字段多条件查询
            array_unshift($param, $field);
            $where = $param;
        } elseif ($field && is_null($condition)) {
            if (is_string($op) && in_array(strtoupper($op), ['NULL', 'NOTNULL', 'NOT NULL'], true)) {
                // null查询
                $where = [$field, $op, ''];
            } elseif ('=' === $op || is_null($op)) {
                $where = [$field, 'NULL', ''];
            } elseif ('<>' === $op) {
                $where = [$field, 'NOTNULL', ''];
            } else {
                // 字段相等查询
                $where = $this->whereEq($field, $op);
            }
        } elseif (is_string($op) && in_array(strtoupper($op), ['EXISTS', 'NOT EXISTS', 'NOTEXISTS'], true)) {
            $where = [$field, $op, is_string($condition) ? new Raw($condition) : $condition];
        } else {
            $where = $field ? [$field, $op, $condition, $param[2] ?? null] : [];
        }

        return $where;
    }

    /**
     * 相等查询的主键处理
     * @access protected
     * @param string $field 字段名
     * @param mixed  $value 字段值
     * @return array
     */
    protected function whereEq(string $field, $value): array
    {
        if ($this->getPk() == $field) {
            $this->options['key'] = $value;
        }

        return [$field, '=', $value];
    }

    /**
     * 数组批量查询
     * @access protected
     * @param array  $field 批量查询
     * @param string $logic 查询逻辑 and or xor
     * @return $this
     */
    protected function parseArrayWhereItems(array $field, string $logic)
    {
        $where = [];
        foreach ($field as $key => $val) {
            if (is_int($key)) {
                $where[] = $val;
            } elseif ($val instanceof Raw) {
                $where[] = [$key, 'exp', $val];
            } else {
                $where[] = is_null($val) ? [$key, 'NULL', ''] : [$key, is_array($val) ? 'IN' : '=', $val];
            }
        }

        if (!empty($where)) {
            $this->options['where'][$logic] = isset($this->options['where'][$logic]) ?
                array_merge($this->options['where'][$logic], $where) : $where;
        }

        return $this;
    }

    /**
     * 多字段组合查询,eg：
     * 用法1：单个数组查询
     * $query->whereGroupField([
     *    "warehouse_id" => 1,
     *    "product_id"   => 12345,
     *    "position_id"  => 11
     * ])->select()
     *
     * 用法2：二维数组批量查询
     * $query->whereGroupField([
     *  [
     *    "warehouse_id" => 1,
     *    "product_id"   => 12345,
     *    "position_id"  => 11
     *  ],
     *  [
     *    "warehouse_id" => 1,
     *    "product_id"   => 12345,
     *    "position_id"  => 11
     *  ]
     * ])->select()
     * @param array $fieldValues
     * @param string $logic
     * @return $this
     */
    public function whereGroupField(array $fieldValues, string $logic = 'AND')
    {
        if (isset($fieldValues[0]) && is_array($fieldValues[0])) {
            $fields = array_keys($fieldValues[0]);
            foreach ($fieldValues as $fieldValue) {
                $values = array_values($fieldValue);
                $valuesCollection[] = "(".implode(",", $values).")";
            }

        }else {
            $fields = array_keys($fieldValues);
            $values = array_values($fieldValues);
            $valuesCollection[] = "(".implode(",", $values).")";
        }

        if (empty($valuesCollection)) {
            throw new \Exception("whereGroupField fieldValues is empty");
        }

        $this->whereRaw("(".implode(",", $fields).") in (".implode(",", $valuesCollection).")",[], $logic);
        return $this;
    }

    /**
     * 去除某个查询条件
     * @access public
     * @param string $field 查询字段
     * @param string $logic 查询逻辑 and or xor
     * @return $this
     */
    public function removeWhereField(string $field, string $logic = 'AND')
    {
        $logic = strtoupper($logic);

        if (isset($this->options['where'][$logic])) {
            foreach ($this->options['where'][$logic] as $key => $val) {
                if (is_array($val) && $val[0] == $field) {
                    unset($this->options['where'][$logic][$key]);
                }
            }
        }

        return $this;
    }

    /**
     * 条件查询
     * @access public
     * @param mixed         $condition 满足条件（支持闭包）
     * @param Closure|array $query     满足条件后执行的查询表达式（闭包或数组）
     * @param Closure|array $otherwise 不满足条件后执行
     * @return $this
     */
    public function when($condition, $query, $otherwise = null)
    {
        if ($condition instanceof Closure) {
            $condition = $condition($this);
        }

        if ($condition) {
            if ($query instanceof Closure) {
                $query($this, $condition);
            } elseif (is_array($query)) {
                $this->where($query);
            }
        } elseif ($otherwise) {
            if ($otherwise instanceof Closure) {
                $otherwise($this, $condition);
            } elseif (is_array($otherwise)) {
                $this->where($otherwise);
            }
        }

        return $this;
    }
}
