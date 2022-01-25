<?php

namespace Common\Library\Tests\Db;

use Common\Library\Db\SqlBuilder;
use PHPUnit\Framework\TestCase;
use Common\Library\Protobuf\Serializer;
use Common\Library\ArrayHelper\ArrayUtil;

class DbTest extends TestCase
{

    public $userId = 123654;

    public function testInsert()
    {
        try {
            $order = new \Common\Library\Tests\Db\Order($this->userId, 0);
            $order->user_id = $this->userId;
            $order->exp('order_amount','order_amount+5');
            $order->order_product_ids = [1234455, 4567888];
            $order->order_status = 1;
            $order->remark = '尽快发货-nnn';
            //$order->json_data = ['go','php','java','swoole'];
            $order->json_data = ['1234455', '4567888'];
            $order->nnnn = 'njnnnj';
            //var_dump($order->getData());
            $order->save();
            var_dump($order->order_id, $order->getNumRows());

        } catch (\Exception $e) {
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

    public function testGroupFind()
    {

        $db = \Common\Library\Tests\Db\Order::model($this->userId)->getSlaveConnection();

        $sql = 'select sum(order_amount) as total, a.user_id, a.order_amount, a.order_status from tbl_order as a where 1=1 ';
        $params = [];

        $groupFields = ['user_id','order_amount','order_status'];
        $groupValue = ['(10000,105,1)', '(10000,123.5,1)'];

        SqlBuilder::buildGroupFieldWhere('a', $groupFields, $groupValue, $sql, $params);

        SqlBuilder::buildDateRange('a', 'create_time','2021-10-01','2022-12-01',$sql, $params);

        //SqlBuilder::buildLike('a', 'remark','%test-remark',$sql, $params);

        SqlBuilder::buildFindInSet('a','order_product_ids',1, $sql, $params);

        SqlBuilder::buildGroupBy('a', $groupFields,$sql, $params);
        SqlBuilder::buildHaving('a', 'total > 1200' ,$sql, $params);

        SqlBuilder::buildOrderBy('a', ['order_amount' => 'DESC'], $sql, $params);
        SqlBuilder::buildLimit('a', 0, 10, $sql, $params);

        $result = $db->createCommand($sql)
            ->queryAll($params);

        $sql = $db->getLastSql();

        var_dump($sql, $result);
    }

    public function testGroupFind1()
    {

        $db = \Common\Library\Tests\Db\Order::model($this->userId)->getSlaveConnection();

        $sql = 'select a.user_id, a.order_amount, a.order_status from tbl_order as a where 1=1 ';
        $params = [];

        SqlBuilder::buildEqualWhere('a','order_status'," 1 or 2=2", $sql, $params);
        SqlBuilder::buildDateRange('a', 'create_time','2021-10-01','2022-12-01',$sql, $params);

        SqlBuilder::buildLike('a', 'remark','%test-remark',$sql, $params);

        //SqlBuilder::buildFindInSet('a','order_product_ids','1', $sql, $params);


        SqlBuilder::buildOrderBy('a', ['order_amount' => 'DESC'], $sql, $params);
        SqlBuilder::buildLimit('a', 0, 10, $sql, $params);

        $result = $db->createCommand($sql)
            ->queryAll($params);

        $sql = $db->getLastSql();

        var_dump($sql, $result);
    }

    public function testFindObject()
    {
        $orderId = 1642787884;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);

        var_dump($order->toArray());
    }

    public function testUpdateObject()
    {
        $orderId = 1642787884;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);
        $order->order_product_ids = [1, 2, 3, 4, 5, 6, 7, 8];
        $order->remark = '中国小米（mi）' . rand(1, 1000);
        //$order->exp('order_amount','order_amount+5');
        //$order->inc('order_amount', 20);
        //$order->inc('order_amount', 15);
        //$order->exp('order_amount', 'order_amount*2');

        $order->sub('order_amount',700);
        $order->save();

        //$order->inc('order_amount', 5);
        var_dump($order->getExpFields());
        $order->save();

        $diff = $order->getDirtyAttributeFields();
        var_dump($diff);

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
        $orderId = '1623132269';
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);

        $connection = $order->getConnection();

        // 启动事务，底层将关闭自动提交
        $connection->beginTransaction();

        try {

            $this->testUpdateObject();

            $connection->createCommand("insert into tbl_order (`order_id`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:user_id,:order_amount,:order_product_ids,:order_status)")->insert([
                ':order_id' => time(),
                ':user_id' => $this->userId,
                ':order_amount' => 100,
                ':order_product_ids' => json_encode([1, 2, 3]),
                ':order_status' => 1
            ]);

            $connection->createCommand("insert into tbl_order (`order_id`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:user_id,:order_amount,:order_product_ids,:order_status)")->insert([
                ':order_id' => time() + 1,
                ':user_id' => $this->userId,
                ':order_amount' => 101,
                ':order_product_ids' => json_encode([1, 2, 3]),
                ':order_status' => 1
            ]);

            // 多层嵌套事务

            // 启动事务，底层将关闭自动提交
            $connection->beginTransaction();

            try {
                $connection->createCommand("insert into tbl_order (`order_id`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:user_id,:order_amount,:order_product_ids,:order_status)")->insert([
                    ':order_id' => time() + 5,
                    ':user_id' => $this->userId,
                    ':order_amount' => 105,
                    ':order_product_ids' => json_encode([1, 2, 3]),
                    ':order_status' => 1
                ]);

                $connection->commit();

            } catch (\PDOException $e) {
                $connection->rollback();
                var_dump($e->getMessage());
            }

            // 提交后，底层将恢复原来的自动提交属性
            $connection->commit();

        } catch (\PDOException $e) {
            $connection->rollback();
        }

        var_dump('success');

//        // 后续继续执行各种curd操作
//        $connection->createCommand("insert into tbl_order (`order_id`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:user_id,:order_amount,:order_product_ids,:order_status)")->insert([
//            ':order_id' => time() + 2,
//            ':user_id' => $this->userId,
//            ':order_amount' => 102,
//            ':order_product_ids' => json_encode([1, 2, 3]),
//            ':order_status' => 1
//        ]);

    }

    public function testJson()
    {
        $orderId = 1632219150;
        $order = new \Common\Library\Tests\Db\Order($this->userId, $orderId);

        $connection = $order->getConnection();

        $id = '"1234455"';
        $result = $connection->createCommand("select * from tbl_order where JSON_CONTAINS(order_product_ids, '1234455') or JSON_CONTAINS(order_product_ids, '{$id}')")->queryAll();

        var_dump($result);

    }
}