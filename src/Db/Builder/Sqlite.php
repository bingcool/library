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

namespace Common\Library\Db\Builder;

use Common\Library\Db\AbstractBuilder;
use Common\Library\Db\Query;
use Common\Library\Db\Raw;

/**
 * Sqlite数据库驱动
 */
class Sqlite extends AbstractBuilder
{
    /**
     * limit
     * @access public
     * @param Query $query 查询对象
     * @param mixed $limit
     * @return string
     */
    public function parseLimit(Query $query, string $limit): string
    {
        $limitStr = '';

        if (!empty($limit)) {
            $limit = explode(',', $limit);
            if (count($limit) > 1) {
                $limitStr .= ' LIMIT ' . $limit[1] . ' OFFSET ' . $limit[0] . ' ';
            } else {
                $limitStr .= ' LIMIT ' . $limit[0] . ' ';
            }
        }

        return $limitStr;
    }

    /**
     * 随机排序
     * @access protected
     * @param Query $query 查询对象
     * @return string
     */
    protected function parseRand(Query $query): string
    {
        return 'RANDOM()';
    }

    /**
     * 字段和表名处理
     * @access public
     * @param Query $query  查询对象
     * @param mixed $key    字段名
     * @param bool  $strict 严格检测
     * @return string
     */
    public function parseKey(Query $query, $key, bool $strict = false): string
    {
        if (is_int($key)) {
            return (string) $key;
        } elseif ($key instanceof Raw) {
            return $this->parseRaw($query, $key);
        }

        $key = trim($key);

        if (strpos($key, '.') && !preg_match('/[,\'\"\(\)`\s]/', $key)) {
            [$table, $key] = explode('.', $key, 2);

            $alias = $query->getOptions('alias');

            if ('__TABLE__' == $table) {
                $table = $query->getOptions('table');
                $table = is_array($table) ? array_shift($table) : $table;
            }

            if (isset($alias[$table])) {
                $table = $alias[$table];
            }
        }

        if ('*' != $key && !preg_match('/[,\'\"\*\(\)`.\s]/', $key)) {
            $key = '`' . $key . '`';
        }

        if (isset($table)) {
            $key = '`' . $table . '`.' . $key;
        }

        return $key;
    }

    /**
     * 设置锁机制
     * @access protected
     * @param Query       $query 查询对象
     * @param bool|string $lock
     * @return string
     */
    protected function parseLock(Query $query, $lock = false): string
    {
        return '';
    }
}
