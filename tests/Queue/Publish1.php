<?php

include_once dirname(dirname(__DIR__)) . "/vendor/autoload.php";


$redis = new \Common\Library\Redis\Redis();
$redis->connect('127.0.0.1');


$queue = new \Common\Library\Queues\Queue(
    $redis,
    'ali_queue_key'
);

$queue->push(['kkkk', 'lllllll']);



