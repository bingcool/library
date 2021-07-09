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
            $order->remark = '尽快发货-nnn';
            //$order->json_data = ['go','php','java','swoole'];
            $order->json_data = ['1234455','4567888'];
            $order->nnnn = 'njnnnj';
            var_dump($order->getData());
            $order->save();
            var_dump($order->order_id, $order->getNumRows());

        }catch (\Exception $e)
        {
            var_dump($e->getMessage());
        }

        // 可以多次调用save(执行update)
        //$order->remark = '中美关系';
        //$order->save();
    }

    public function testFindList()
    {
        $result = \Common\Library\Tests\Db\Order::model($this->userId)->getSlaveConnection()
            ->createCommand('select * from tbl_order order by create_time desc LIMIT 4 ')
            ->queryAll();

        var_dump($result);
    }

    public function testFindObject()
    {
        $orderId = 1623132269;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);
        var_dump($order->getOldAttributeValue('remark', true));
    }

    public function testUpdateObject()
    {
        $orderId = 1623132269;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);
        $order->order_product_ids = [1,2,3,4,5,6,7,8];
        $order->remark = '中国小米（mi）'.rand(1,1000);
        $order->save();

        $diff = $order->getDirtyAttributeFields();

        var_dump($order->order_id, $diff);
    }

    public function testDeleteObject()
    {
        $orderId = 1623187838;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);
        $order->delete();

    }

    /**
     * 测试事务
     */
    public function testTransaction()
    {
        $orderId = 1623132269;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);

        $connection = $order->getConnection();

        // 启动事务，底层将关闭自动提交
        $connection->beginTransaction();

        try{

            $this->testUpdateObject();

            $connection->createCommand("insert into tbl_order (`order_id`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:user_id,:order_amount,:order_product_ids,:order_status)" )->insert([
                ':order_id' => time(),
                ':user_id' => $this->userId,
                ':order_amount' => 100,
                ':order_product_ids' => json_encode([1,2,3]),
                ':order_status' => 1
            ]);

            $connection->createCommand("insert into tbl_order (`order_id`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:user_id,:order_amount,:order_product_ids,:order_status)" )->insert([
                ':order_id' => time() + 1,
                ':user_id' => $this->userId,
                ':order_amount' => 101,
                ':order_product_ids' => json_encode([1,2,3]),
                ':order_status' => 1
            ]);

            // 多层嵌套事务
            try{
                $connection->createCommand("insert into tbl_order (`order_id`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:user_id,:order_amount,:order_product_ids,:order_status)" )->insert([
                    ':order_id' => time() + 5,
                    ':user_id' => $this->userId,
                    ':order_amount' => 105,
                    ':order_product_ids' => json_encode([1,2,3]),
                    ':order_status' => 1
                ]);

                $connection->commit();

            }catch (\PDOException $e)
            {
                $connection->rollback();
                var_dump($e->getMessage());
            }

            // 提交后，底层将恢复原来的自动提交属性
            $connection->commit();

        }catch (\PDOException $e)
        {
            $connection->rollback();
            var_dump($e->getMessage());
        }

        // 后续继续执行各种curd操作
        $connection->createCommand("insert into tbl_order (`order_id`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:user_id,:order_amount,:order_product_ids,:order_status)" )->insert([
            ':order_id' => time() + 2,
            ':user_id' => $this->userId,
            ':order_amount' => 102,
            ':order_product_ids' => json_encode([1,2,3]),
            ':order_status' => 1
        ]);

    }

    public function testJson()
    {
        $orderId = 1623132269;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);

        $connection = $order->getConnection();

        $id = '"1234455"';
        $result = $connection->createCommand("select * from tbl_order where JSON_CONTAINS(order_product_ids, '1234455') or JSON_CONTAINS(order_product_ids, '{$id}')" )->queryAll();

        var_dump($result);

    }
}