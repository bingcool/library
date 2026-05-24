<?php

include_once dirname(dirname(__DIR__)) . "/vendor/autoload.php";


$redis = new \Swoolefy\Library\Redis\Redis();
$redis->connect('127.0.0.1');


$queue = new \Swoolefy\Library\Queues\Queue(
    $redis,
    'ali_queue_key'
);

$queue->push(['kkkk', 'lllllll']);



