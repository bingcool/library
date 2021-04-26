<?php

include_once dirname(dirname(__DIR__))."/vendor/autoload.php";

//
//$redis = new \Common\Library\Cache\Redis();
//$redis->connect('127.0.0.1');

$redis = new \Common\Library\Cache\Predis([
    'scheme' => 'tcp',
    'host'   => '127.0.0.1',
    'port'   => 6379,
]);

$redis->connect();


$queue = new \Common\Library\Queue\PredisDelayQueue(
    $redis,
    'ali_delay_key'
);

$queue->addItem(0, 123, 5)
    ->addItem(time(), 124, 10)
    ->push();

var_dump($queue->count('-inf','+inf'));


//$queue->rem([123]);

var_dump($queue->count('-inf','+inf'));


var_dump($queue->range(0,time() + 1));

var_dump($queue->incrBy(2,124));


$startTime = 0;

while (true)
{
    sleep(1);

    $endTime = time();

    $result = $queue->rangeByScore('-inf', time(),  ['limit' =>[0,9], 'withscores'=> 1]);


    $startTime = $endTime - 10;

    var_dump($result);
}