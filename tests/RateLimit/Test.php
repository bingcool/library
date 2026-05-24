<?php

include_once dirname(dirname(__DIR__)) . "/vendor/autoload.php";

if (isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] == 1) {
    $redis = new \Swoolefy\Library\Redis\Predis([
        'scheme' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 6379,
    ]);
    $redis->connect();
    $pubSub = new \Swoolefy\Library\PubSub\PredisPubSub($redis);
    var_dump('use Predis driver');
} else {

    $redis = new \Swoolefy\Library\Redis\Redis();
    $redis->connect('127.0.0.1');

    $pubSub = new \Swoolefy\Library\PubSub\RedisPubSub($redis);

    var_dump('use phpredis driver');
}


while (true) {
    $rateLimit = new \Swoolefy\Library\RateLimit\DurationLimiter($redis);
    $key = 'ali-query-api';
    $limitTime = 1;
    $limitNum = 200;
    $remainTime = 60;
    $isLimit = $rateLimit->checkLimit($key, $limitTime, $limitNum, $remainTime);

    if ($isLimit) {
        var_dump('limit');
        usleep(20000);
    } else {
        var_dump('coroutine');
    }

}
