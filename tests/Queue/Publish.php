<?php

include_once dirname(dirname(__DIR__)) . "/vendor/autoload.php";


$redis = new \Common\Library\Redis\Redis();
$redis->connect('127.0.0.1');


$queue = new \Common\Library\Queues\RedisDelayQueue(
    $redis,
    'ali_delay_key'
);

$num = $queue->addItem(time(), 123, 5)
    ->addItem(time(), 124, 10)
    ->push();

var_dump($num);


