<?php

namespace Common\Library\Tests\Db;

use PHPUnit\Framework\TestCase;
use Common\Library\Protobuf\Serializer;
use Common\Library\ArrayHelper\ArrayUtil;

class DbTest extends TestCase
{

    public $userId = 123654;

    public function testInsert()
    {
        try {
            $order = new \Common\Library\Tests\Db\Order($this->userId,0);
            $order->user_id = $this->userId;
            $order->order_amount = 100.50;
            $order->order_product_ids = [1234455,4567888];
            $order->order_status = 1;
            $order->remark = '尽快发货';
            $order->save();
            var_dump($order->order_id, $order->getNumRows());

        }catch (\Exception $e)
        {
            var_dump($e->getMessage());
        }
    }

    public function testFindList()
    {
        $result = \Common\Library\Tests\Db\Order::model($this->userId)->getSlaveConnection()
            ->createCommand('select * from tbl_order')
            ->queryAll();

        var_dump($result);
    }

    public function testFindObject()
    {
        $orderId = 1623132269;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);
        var_dump($order->getAttributes());
    }

    public function testUpdateOnject()
    {
        $orderId = 1623132269;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);
        $order->order_product_ids = [1,2,3,4,5,6,7,8];
        $order->remark = '中国小米（mi）';
        $order->save();
        var_dump($order->order_id, $order->getNumRows());
    }

    public function testDeleteObject()
    {
        $orderId = 1623187838;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);
        $order->delete();

    }
}