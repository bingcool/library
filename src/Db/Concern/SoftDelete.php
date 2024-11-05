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

use Common\Library\Db\PDOConnection;
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
    public static function getSoftDeleteField()
    {
        return static::$softDeleteField;
    }

    /**
     * @param PDOConnection $connection
     * @return Query
     */
    public static function withoutTrashed(?PDOConnection $connection = null): Query
    {
        $model = new static();
        $model->__enableSoftDelete = false;
        if (!is_object($connection)) {
            $connection = $model->getConnection();
        }
        if (method_exists($connection, 'getObject')) {
            $query = (new Query($connection->getObject()))->table($model->getTableName());
        }else {
            $query = (new Query($connection))->table($model->getTableName());
        }
        $query->setModel($model);
        return $query;
    }

    /**
     * 恢复被软删除的记录
     * @param mixed $pkValue 主键值
     * @return bool
     */
    public function restore($pkValue): bool
    {
        if ($this->isSoftDelete() && $pkValue) {
            if (method_exists($this->getConnection(), 'getObject')) {
                $query = (new Query($this->getConnection()->getObject()))->table($this->getTableName());
            }else {
                $query = (new Query($this->getConnection()))->table($this->getTableName());
            }
            $deletedField = $this->getSoftDeleteField();
            $query->where($this->getPk(),'=', $pkValue);
            $query->whereNotNull($deletedField);
            $query->restore($deletedField);
        }
        return true;
    }
}