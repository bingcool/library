<?php

include_once dirname(dirname(__DIR__)) . "/vendor/autoload.php";


$metaBrokerList = '192.168.99.103:9092';
$topicName = 'mykafka';

$consumer = new \Common\Library\Kafka\Consumer($metaBrokerList, $topicName);

$consumer->setRebalanceCb(function (\RdKafka\KafkaConsumer $kafkaConsumer, $err, $partitions) {
    switch ($err) {
        case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
            var_dump('Assign Partitions :');
            var_dump($partitions);

            $kafkaConsumer->assign($partitions);

            break;

        case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
            var_dump("Revoke: ");
            var_dump($partitions);
            $kafkaConsumer->assign(NULL);
            break;

        default:
            throw new Exception($err);
    }
});

// 设置conf的配置项
$consumer->setConfig([
    // 自动提交offset如果设置为0,那么是手动提交，需要自己在程序最后commit()
    'enable.auto.commit' => 1
]);

// 设置消费分组Id
$consumer->setGroupId('group_order_pay');
// 订阅返回消费实例
$rdKafkaConsumer = $consumer->subject();

while (true) {
    // 10s 超时
    $message = $rdKafkaConsumer->consume(10 * 1000);

    if (!empty($message)) {
        var_dump('offset=' . $message->offset . '--- err=' . $message->err . '--partition=' . $message->partition);
        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                //  解释数据
                $payload = json_decode($message->payload, true) ?? $message->payload;
                //var_dump($payload);
                break;
            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                rd_kafka_err2str(RD_KAFKA_RESP_ERR__PARTITION_EOF);
                echo "No more messages; will wait for more";
                break;
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                var_dump('time out!');
                break;
            default:
                var_dump("nothing");
                throw new Exception($message->errstr(), $message->err);
                break;
        }
    }

}
