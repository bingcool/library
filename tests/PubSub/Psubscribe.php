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
    try {
        $pubSub->psubscribe(['test1*'], function($redis, $pattern, $channel, $msg) use($pubSub) {
            switch ($channel)
            {
                case 'test1':
                        var_dump($msg, $channel);
                    break;
            }
            var_dump($msg, $channel);
            //var_dump($pubSub->punsubscribe(['test1*']));
        });
    }catch (\Exception $exception)
    {
        var_dump($exception->getMessage());
    }

}
