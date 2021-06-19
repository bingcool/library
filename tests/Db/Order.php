<?php

namespace Common\Library\Tests\Db;

/**
* @property integer $order_id
* @property integer $user_id
* @property float $order_amount
* @property string $order_product_ids
* @property string $json_data
* @property integer $order_status
* @property string $remark
* @property string $create_time
* @property string $update_time
*/

class Order extends ActiveRecord
{
    use PropertyFormat;

    /**
     * @var string
     */
    protected $table = 'tbl_order';

    protected $pk = 'order_id';

    /**
     * Image constructor.
     * @param int $id
     */
    public function __construct($userId, $id = 0)
    {
        parent::__construct($userId);
        if($id)
        {
            $this->loadByPk($id);
        }
    }

    public function loadByPk($id, ...$params)
    {
        $this->findOne('order_id=:order_id',[

            ':order_id' => $id
        ]);
        return $this;
    }

    public function onBeforeInsert(): bool
    {
        return parent::onBeforeInsert();
    }

    public function onAfterInsert()
    {
        parent::onAfterInsert();
    }

    public function onAfterUpdate()
    {
        if($this->isDirty('order_product_ids'))
        {
            var_dump('change');
        }
        var_dump($this->getDiffAttributes());
        if($this->isNew())
        {
            var_dump('isNew');
        }else
        {
            var_dump('noNew');
        }

        if($this->isExists())
        {
            var_dump('isExists');
        }else
        {
            var_dump('no isExists');
        }
        parent::onAfterUpdate();
    }

    protected function processDelete(): bool
    {
        var_dump('processDelete');
        return parent::processDelete();
    }

    protected function onAfterDelete()
    {

        var_dump($this->remark,'afterDelete');
        parent::onAfterDelete();
    }
}