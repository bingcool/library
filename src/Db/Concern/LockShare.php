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
 * 乐观锁条件
 */
trait LockShare
{

    /**
     * where 条件
     *
     * @var array
     */
    protected $lockShareWhereFieldValues = [];

    /**
     * model->save()或者model->update()有些场景下需要支持乐观锁的条件限定更新，而不是仅仅是主键pk一个条件
     * $order = new OrderEntity();
     * $order->loadById(1685959471);
     *
     * // 要更新的数据
     * $order->status = 2;
     * $order->lockShareWhere([ // 这里添加乐观锁条件，status=1时才能更新到2
            'status' => 1
        ]);
       $order->save();
     * @param array $where
     * @return void
     */
    public function lockShareWhere(array $where)
    {
        $this->lockShareWhereFieldValues = $where;
    }
}