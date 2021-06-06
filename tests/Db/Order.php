<?php

namespace Common\Library\Tests\Db;

use Common\Library\Db\PDOConnection;

class Order extends ActiveRecord
{
    /**
     * @var string
     */
    protected $table = 'tbl_order';

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
        parent::onAfterUpdate();
    }


}