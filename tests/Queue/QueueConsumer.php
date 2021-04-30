<?php

include_once dirname(dirname(__DIR__))."/vendor/autoload.php";

if(isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] == 1)
{
    $redis = new \Common\Library\Cache\Predis([
        'scheme' => 'tcp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
    ]);

    $redis->connect();
    var_dump("use Predis driver");
}else
{
    $redis = new \Common\Library\Cache\Redis();
    $redis->connect('127.0.0.1');
    var_dump('use Phpredis driver');
}

$queue = new \Common\Library\Queues\Queue(
    $redis,
    'ali_queue_key'
);


$queue->getRedis()->del('ali_queue_key');

for($i=1; $i<=2; $i++)
{
    $item = [
        'id' => $i,
        'name' => 'bingcool-'.$i
    ];

    $queue->push($item);
}

$queue->delRetryMessageKey();

while(1)
{
    try {
        $ret = $queue->pop($timeOut = 0);

        var_dump($ret, $queue->count());

        if($ret)
        {
            $data = json_decode($ret[1], true) ?? [];

            // 假设处理失败，然后放入失败重试队列，一定时间后再处理
            if(isset($data['id']) && $data['id'] == 2)
            {
                $queue->retry($ret[1], 5);
            }
        }

    }catch (\Exception $e)
    {

    }
}