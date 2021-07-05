<?php

include_once dirname(dirname(__DIR__))."/vendor/autoload.php";

if(isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] == 1) {
    $redis = new \Common\Library\Cache\Predis([
        'scheme' => 'tcp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
    ]);
    $redis->connect();
    $pubSub = new \Common\Library\PubSub\PredisPubSub($redis);
    var_dump( 'use Predis driver');
}else {

    $redis = new \Common\Library\Cache\Redis();
    $redis->connect('127.0.0.1');

    $pubSub = new \Common\Library\PubSub\RedisPubSub($redis);

    var_dump('use phpredis driver');
}


while (true)
{
    $rateLimit = new \Common\Library\RateLimit\RedisLimit($redis);
    $key = 'ali-query-api';
    $limitTime = 1;
    $limitNum = 200;
    $remainTime = 60;
    $isLimit = $rateLimit->checkLimit($key, $limitTime, $limitNum, $remainTime);

    if($isLimit)
    {
        var_dump('limit');
        usleep(20000);
    }else {
        var_dump('coroutine');
    }

}
