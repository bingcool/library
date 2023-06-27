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
{/**
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
