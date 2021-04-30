<?php

include_once dirname(dirname(__DIR__))."/vendor/autoload.php";

if(isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] == 1) {
    $redis = new \Common\Library\Cache\Predis([
        'scheme' => 'tcp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
    ]);
    $redis->connect();
    $queue = new \Common\Library\Queues\PredisDelayQueue(
        $redis,
        'ali_delay_key'
    );
    var_dump( 'use Predis driver');
}else {

    $redis = new \Common\Library\Cache\Redis();
    $redis->connect('127.0.0.1');

    $queue = new \Common\Library\Queues\RedisDelayQueue(
        $redis,
        'ali_delay_key'
    );

    var_dump('use phpredis driver');
}

$member1 = json_encode(['lead_id'=>123,'name'=>'lead1']);
$member2 = json_encode(['lead_id'=>124,'name'=>'lead2']);

$queue->addItem(time(), 123, 5)
    ->addItem(time(), 124, 10)
    ->push();

var_dump($queue->count('-inf','+inf'));


//$queue->rem([123]);

var_dump($queue->count('-inf','+inf'));


var_dump($queue->range(0, time() + 1));

var_dump($queue->incrBy(2,124));


$startTime = 0;

$queue->getRedis()->del($queue->getRetryMessageKey());

while (true)
{
    sleep(1);

    $endTime = time();

    $result = $queue->rangeByScore('-inf', time(),  ['limit' =>[0,9]]);

    $startTime = $endTime - 10;

    foreach($result as $id)
    {
        if($id == 123)
        {
            $queue->retry(123, 5);
        }
    }

    var_dump($result);
}