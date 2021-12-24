<?php

include_once dirname(dirname(__DIR__)) . "/vendor/autoload.php";

if (isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] == 1) {
    $redis = new \Common\Library\Cache\Predis([
        'scheme' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 6379,
    ]);
    $redis->connect();
    $pubSub = new \Common\Library\PubSub\PredisPubSub($redis);
    var_dump('use Predis driver');
} else {

    $redis = new \Common\Library\Cache\Redis();
    $redis->connect('127.0.0.1');
    $pubSub = new \Common\Library\PubSub\RedisPubSub($redis);
//    $redis = new \Redis();
//    $redis->pconnect(
//        '127.0.0.1',
//        $port = 6379,
//        $timeout = 0.0,
//        $reserved = null,
//        $retryInterval = 1,
//        $readTimeout = 0.0
//    );
//    $pubSub = new \Common\Library\PubSub\RedisPubSub($redis);
    var_dump('use phpredis driver');
}

while (true) {
    try {
        //
        $pubSub->subscribe(['test1'], function ($redis, $chan, $msg) use ($pubSub) {
            switch ($chan) {
                case 'test1':
                    var_dump($msg);
                    break;
            }
        });
    } catch (\Exception $exception) {
        var_dump($exception->getMessage());
    }

}

