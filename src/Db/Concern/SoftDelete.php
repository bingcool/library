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

use Common\Library\Db\Query;
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
    public $__enableSoftDelete = true;

    /**
     * @var string
     */
    protected static $softDeleteField = 'deleted_at';

    /**
     * @return mixed|string
     */
    public function getSoftDeleteField()
    {
        return static::$softDeleteField;
    }

    /**
     * @return $this
     */
    public static function withoutTrashed()
    {
        $model = new static();
        $model->__enableSoftDelete = false;
        if (method_exists($model->getConnection(), 'getObject')) {
            $query = (new Query($model->getConnection()->getObject()))->table($model->getTableName());
        }else {
            $query = (new Query($model->getConnection()))->table($model->getTableName());
        }
        $query->setModel($model);
        return $query;
    }

}