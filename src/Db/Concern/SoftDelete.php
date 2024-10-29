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
 * 软删除
 */
trait SoftDelete
{
    /**
     * Indicates if the model is currently force deleting.
     *
     * @var bool
     */
    protected $enableSoftDelete = true;

    /**
     * @var string
     */
    protected static $softDeleteField = 'deleted_at';

    /**
     * @return mixed|string
     */
    protected function getSoftDeleteField()
    {
        return static::$softDeleteField;
    }

    /**
     * @return $this
     */
    public function withoutTrashed()
    {
        $this->enableSoftDelete = false;
        return $this;
    }

}