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

use Common\Library\Db\Raw;
use Common\Library\Exception\DbException;

/**
 * 聚合查询
 */
trait AggregateQuery
{
    /**
     * 聚合查询
     * @access protected
     * @param string     $aggregate 聚合方法
     * @param string|Raw $field     字段名
     * @param bool       $force     强制转为数字类型
     * @return mixed
     */
    protected function aggregate(string $aggregate, $field, bool $force = false)
    {
        if (is_string($field) && 0 === stripos($field, 'DISTINCT ')) {
            [$distinct, $field] = explode(' ', $field);
        }

        $field = $aggregate . '(' . (!empty($distinct) ? 'DISTINCT ' : '') . $this->builder->parseKey($this, $field, true) . ') AS think_' . strtolower($aggregate);

        $result = $this->value($field, 0);

        return $force ? (float) $result : $result;
    }

    /**
     * COUNT查询
     * @access public
     * @param string|Raw $field 字段名
     * @return int
     */
    public function count(string $field = '*'): int
    {
        if (!empty($this->options['group'])) {
            // 支持GROUP

            if (!preg_match('/^[\w\.\*]+$/', $field)) {
                throw new DbException('not support data:' . $field);
            }

            $options = $this->getOptions();

            $subSql = $this->options($options)
                ->field('count(' . $field . ') AS think_count')
                ->bind($this->bind)
                ->buildSql();

            $query = $this->newQuery();

            $query->table([$subSql => '_group_count_']);

            $count = $query->aggregate('COUNT', '*');
        } else {
            $count = $this->aggregate('COUNT', $field);
        }

        return (int) $count;
    }

    /**
     * SUM查询
     * @access public
     * @param string|Raw $field 字段名
     * @return float
     */
    public function sum($field): float
    {
        return $this->aggregate('SUM', $field, true);
    }

    /**
     * MIN查询
     * @access public
     * @param string|Raw $field 字段名
     * @param bool       $force 强制转为数字类型
     * @return mixed
     */
    public function min($field, bool $force = true)
    {
        return $this->aggregate('MIN', $field, $force);
    }

    /**
     * MAX查询
     * @access public
     * @param string|Raw $field 字段名
     * @param bool       $force 强制转为数字类型
     * @return mixed
     */
    public function max($field, bool $force = true)
    {
        return $this->aggregate('MAX', $field, $force);
    }

    /**
     * AVG查询
     * @access public
     * @param string|Raw $field 字段名
     * @return float
     */
    public function avg($field): float
    {
        return $this->aggregate('AVG', $field, true);
    }

}
