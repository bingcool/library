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

use Common\Library\Exception\DbException;
/**
 * 查询数据处理
 */
trait ResultOperation
{

    /**
     * 循环处理数据
     *
     * @param array $result 查询数据
     *
     * @return void
     */
    protected function result(array &$result): void
    {
        // JSON数据处理
        if (!empty($this->options['json'])) {
            $this->jsonResult($result);
        }

        // 循环处理
        if (!empty($this->options['each'])) {
            foreach ($this->options['each'] as $eachHandle ) {
                foreach ($result as $k=>&$item) {
                    $result[$k] = call_user_func_array($eachHandle, [$item, $this->options]);
                }
            }
            unset($item);
        } 

        // 查询数据处理
        if (!empty($this->options['filter'])) {
            foreach ($this->options['filter'] as $filter) {
                $result = call_user_func_array($filter, [$result, $this->options]);
            }
        }

        // 获取器
        if (!empty($this->options['with_attr'])) {
            $this->getResultAttr($result, $this->options['with_attr']);
        }
    }

    /**
     * JSON字段数据转换.
     *
     * @param array $result 查询数据
     *
     * @return void
     */
    protected function jsonResult(array &$result): void
    {
        foreach ($this->options['json'] as $name) {
            foreach ($result as &$item) {
                if (isset($item[$name])) {
                    $item[$name] = json_decode($item[$name], true);
                }
            }
            unset($item);
        }
    }

    /**
     * 设置数据处理（支持模型）.
     *
     * @param callable $filter 数据处理Callable
     * @param string   $index  索引（唯一）
     * @see Query::result()
     * @return $this
     */
    public function filter(callable $filter, ?string $index = null)
    {
        if ($index) {
            $this->options['filter'][$index] = $filter;
        } else {
            $this->options['filter'][] = $filter;
        }

        return $this;
    }

    /**
     * @param callable $filter
     * @see Query::result()
     * @return $this
     */
    public function each(callable $filter)
    {
        $this->options['each'][] = $filter;
        return $this;
    }

    /**
     * 查询失败 抛出异常
     * @access protected
     * @return void
     * @throws DbException
     */
    protected function throwNotFound(): void
    {
        $table = $this->getTable();
        throw new DbException('table data not Found:' . $table);
    }

}
