<?php

include_once dirname(dirname(__DIR__))."/vendor/autoload.php";


$metaBrokerList = '192.168.99.103:9092';
$topicName = 'mykafka';

$producer = new \Common\Library\Kafka\Producer($metaBrokerList, $topicName);

// 可以重新设置注入conf
//$conf = new \RdKafka\Conf();
//$conf->set('bootstrap.servers', $metaBrokerList);
//$producer->setConf($conf);

// 可以重新设置注入topicConf
$topicConf = new \RdKafka\TopicConf();
$topicConf->set('request.required.acks', 1);
$producer->setTopicConf($topicConf);

while (1)
{
    $producer->produce('hello word bingcool!', 5000, 123456);

    sleep(2);
}

