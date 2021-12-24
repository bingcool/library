<?php

namespace Common\Library\Tests\Uuid;

use PHPUnit\Framework\TestCase;
use Common\Library\Protobuf\Serializer;

class ClientTest extends TestCase
{
    public function testRedisIncr()
    {
        $redis = new \Common\Library\Cache\Predis([
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
        ]);

        $UUID = new \Common\Library\Uuid\RedisIncrement($redis, 'order_incr_id');

        //var_dump($redis->ttl('order_incr_id'));

        // 批量获取，批量处理分配
        $list = [
            [
                'name' => 'a1'
            ],
            [
                'name' => 'a2'
            ],
            [
                'name' => 'a3'
            ]
        ];
        $count = count($list);
        $incrId = $UUID->getIncrId(1);
        var_dump($incrId);
//        $id = $incrId - $count;
//        foreach ($list as $item)
//        {
//            var_dump($id++);
//        }

    }

    // 批量获取处理
    public function testRedisIncr1()
    {
//        $redis = new \Common\Library\Cache\Redis();
//        $redis->connect('127.0.0.1');
//
//        $UUID = new \Common\Library\Uuid\RedisIncrement($redis,'order_incr_id');
//
//        // 批量获取，批量处理分配
//        $list = [
//            [
//                'name'=>'a1'
//            ],
//            [
//                'name'=>'a2'
//            ],
//            [
//                'name'=>'a3'
//            ]
//        ];
//        $count = count($list);
//        $incrId = $UUID->getIncrId(4);
//        var_dump($incrId);
//        $id = $incrId - $count;
//        foreach ($list as $item)
//        {
//            var_dump($id++);
//        }

    }
}

