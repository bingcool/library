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

namespace Swoolefy\Library\Db\Concern;


use Swoolefy\Library\Db\Raw;
use Swoolefy\Library\Db\SelectorTable;

/**
 * JOIN和VIEW查询
 */
trait JoinAndViewQuery
{

    /**
     * 查询SQL组装 join
     * @access public
     * @param mixed  $join      关联的表名
     * @param mixed  $condition 条件
     * @param string $type      JOIN类型
     * @param array  $bind      参数绑定
     * @return $this
     */
    public function join($join, string $condition = null, string $type = 'INNER', array $bind = [])
    {
        $table = $this->getJoinTable($join);

        if (!empty($bind) && $condition) {
            $this->bindParams($condition, $bind);
        }

        $this->options['join'][] = [$table, strtoupper($type), $condition];

        return $this;
    }

    /**
     * LEFT JOIN
     * @access public
     * @param mixed $join      关联的表名
     * @param mixed $condition 条件
     * @param array $bind      参数绑定
     * @return $this
     */
    public function leftJoin($join, string $condition = null, array $bind = [])
    {
        return $this->join($join, $condition, 'LEFT', $bind);
    }

    /**
     * RIGHT JOIN
     * @access public
     * @param mixed $join      关联的表名
     * @param mixed $condition 条件
     * @param array $bind      参数绑定
     * @return $this
     */
    public function rightJoin($join, string $condition = null, array $bind = [])
    {
        return $this->join($join, $condition, 'RIGHT', $bind);
    }

    /**
     * FULL JOIN
     * @access public
     * @param mixed $join      关联的表名
     * @param mixed $condition 条件
     * @param array $bind      参数绑定
     * @return $this
     */
    public function fullJoin($join, string $condition = null, array $bind = [])
    {
        return $this->join($join, $condition, 'FULL', $bind);
    }

    /**
     * 获取Join表名及别名 支持
     * ['prefix_table或者子查询'=>'alias'] 'table alias'
     * @access protected
     * @param array|string|Raw $join  join 表名
     * @param string           $alias 别名
     * @return string|array|\Swoolefy\Library\Db\Raw
     */
    protected function getJoinTable($join, &$alias = null)
    {
        if (is_array($join)) {
            $table = $join;
            $alias = array_shift($join);
            return $table;
        } elseif ($join instanceof Raw) {
            return $join;
        }else if ($join instanceof SelectorTable) {
            if ($join->aliasName) {
                $join = join(' ', [$join->tableName, $join->aliasName]);
            }else {
                $join = $join->tableName;
            }
        }

        $join = trim($join);

        if (false !== strpos($join, '(')) {
            // 使用子查询
            $table = $join;
        } else {
            // 使用别名
            if (stripos($join, ' as ') !== false || strpos($join, ' ') !== false) {
                // 使用别名
                $items = explode(' ', $join);
                $table = $items[0];
                $alias = array_pop($items);
            } else {
                $table = $join;
                if (false === strpos($join, '.')) {
                    $alias = $join;
                }
            }

            if ($this->prefix && false === strpos($table, '.') && 0 !== strpos($table, $this->prefix)) {
                $table = $this->getTable($table);
            }
        }

        if (!empty($alias) && $table != $alias) {
            $table = [$table => $alias];
        }

        return $table;
    }

    /**
     * 指定JOIN查询字段
     * @access public
     * @param string|array $join  数据表
     * @param string|array $field 查询字段
     * @param string       $on    JOIN条件
     * @param string       $type  JOIN类型
     * @param array        $bind  参数绑定
     * @return $this
     */
    public function view($join, $field = true, $on = null, string $type = 'INNER', array $bind = [])
    {
        $this->options['view'] = true;

        $fields = [];
        $table  = $this->getJoinTable($join, $alias);

        if (true === $field) {
            $fields = !empty($alias) ? $alias . '.*' : '*';
        } else {
            if (is_string($field)) {
                $field = explode(',', $field);
            }

            foreach ($field as $key => $val) {
                if (is_numeric($key)) {
                    $fields[] = !empty($alias) ? $alias . '.' . $val : $val;

                    $this->options['map'][$val] = !empty($alias) ? $alias . '.' . $val : $val;
                } else {
                    if (preg_match('/[,=\.\'\"\(\s]/', $key)) {
                        $name = $key;
                    } else {
                        $name = !empty($alias) ? $alias . '.' . $key : $key;
                    }

                    $fields[] = $name . ' AS ' . $val;

                    $this->options['map'][$val] = $name;
                }
            }
        }

        $this->field($fields);

        if ($on) {
            $this->join($table, $on, $type, $bind);
        } else {
            $this->table($table);
        }

        return $this;
    }

    /**
     * 视图查询处理
     * @access protected
     * @param array $options 查询参数
     * @return void
     */
    protected function parseView(array &$options): void
    {
        foreach (['AND', 'OR'] as $logic) {
            if (isset($options['where'][$logic])) {
                $updated = [];
                foreach ($options['where'][$logic] as $key => $val) {
                    if (array_key_exists($key, $options['map'])) {
                        if (is_array($val)) {
                            array_shift($val);
                            array_unshift($val, $options['map'][$key]);
                        }
                        $updated[$options['map'][$key]] = $val;
                    } else {
                        $updated[$key] = $val;
                    }
                }
                $options['where'][$logic] = $updated;
            }
        }

        if (isset($options['order'])) {
            // 视图查询排序处理
            foreach ($options['order'] as $key => $val) {
                if (is_numeric($key) && is_string($val)) {
                    if (strpos($val, ' ')) {
                        [$field, $sort] = explode(' ', $val);
                        if (array_key_exists($field, $options['map'])) {
                            $options['order'][$options['map'][$field]] = $sort;
                            unset($options['order'][$key]);
                        }
                    } elseif (array_key_exists($val, $options['map'])) {
                        $options['order'][$options['map'][$val]] = 'asc';
                        unset($options['order'][$key]);
                    }
                } elseif (array_key_exists($key, $options['map'])) {
                    $options['order'][$options['map'][$key]] = $val;
                    unset($options['order'][$key]);
                }
            }
        }
    }

}
