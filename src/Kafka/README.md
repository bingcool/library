# Kafka

基于 PHP 扩展 [rdkafka](https://github.com/arnaud-lb/php-rdkafka)（librdkafka）的 Kafka 生产者 / 消费者封装，统一 Broker、全局配置与 Topic 配置。

命名空间：`Swoolefy\Library\Kafka`

---

## 依赖

| 依赖 | 说明 |
|------|------|
| `ext-rdkafka` | PHP rdkafka 扩展 |
| librdkafka | 底层 C 库 |
| Kafka Broker | 可达的 `metadata.broker.list` |

配置项参考：[librdkafka CONFIGURATION.md](https://github.com/confluentinc/librdkafka/blob/master/CONFIGURATION.md)

---

## 目录结构

```
Kafka/
├── AbstractKafka.php   # Broker / Conf / TopicConf 公共能力
├── Producer.php        # 生产者 produce / producev
├── Consumer.php        # 消费者组 subscribe + consume + 手动 commit
└── README.md
```

---

## 架构概览

```
Producer / Consumer
        │
        ▼
 AbstractKafka  ── setBrokerList / setGlobalProperty / setTopicProperty
        │
        ▼
 RdKafka\Conf + RdKafka\TopicConf  ──►  RdKafka\Producer | KafkaConsumer
```

---

## Producer

### 默认全局配置

| 配置 | 默认 | 说明 |
|------|------|------|
| `enable.idempotence` | `0` | 幂等生产（可按需开启） |
| `message.send.max.retries` | `10` | 发送重试次数 |

### 用法

```php
use Swoolefy\Library\Kafka\Producer;

$producer = new Producer('127.0.0.1:9092', 'topicOrder1');
// Broker 也可传数组：['broker1:9092', 'broker2:9092']

$producer->setGlobalProperty([
    'enable.idempotence' => 0,
    'message.send.max.retries' => 5,
]);
$producer->setTopicProperty([
    // 'request.required.acks' => 1,
]);

// payload, flush 超时(ms), key(同 key 进同分区), partition, msgFlag
$producer->produce(
    json_encode(['order_id' => 1001], JSON_UNESCAPED_UNICODE),
    5000,
    '1001'  // 顺序业务建议传 orderId 等作为 key
);

// 带 headers（需 librdkafka 支持 producev）
$producer->producev(
    'hello',
    5000,
    'key-1',
    ['x-trace-id' => 'abc']
);
```

`produce` / `producev` 内部会 `poll(0)` + `flush($timeoutMs)`，确保消息尽量刷出。

---

## Consumer

### 默认全局配置

| 配置 | 默认 | 说明 |
|------|------|------|
| `enable.auto.commit` | `1` | 自动提交 offset |
| `auto.commit.interval.ms` | `200` | 自动提交间隔 |
| `auto.offset.reset` | `earliest` | 无位点时从最早开始 |
| `session.timeout.ms` | `45000` | 会话超时 |
| `max.poll.interval.ms` | `600000` | 两次 poll 最大间隔，过小易触发不必要 rebalance |

### 用法

```php
use Swoolefy\Library\Kafka\Consumer;

$consumer = new Consumer('127.0.0.1:9092', 'topicOrder1');
$consumer->setGroupId('topic_order_group1');
$consumer->setGlobalProperty([
    'enable.auto.commit' => 1,
    'auto.offset.reset' => 'earliest',
    'max.poll.interval.ms' => 600 * 1000,
]);

// 可选：分区分配 / 回收钩子
$consumer->setAssignPartitionsCallback(function (?array $partitions) {
    // ASSIGN
});
$consumer->setRevokePartitionsCallback(function (?array $partitions) {
    // REVOKE
});

while (true) {
    $message = $consumer->consume(1000); // timeout ms

    switch ($message->err) {
        case RD_KAFKA_RESP_ERR_NO_ERROR:
            $payload = json_decode($message->payload, true) ?? $message->payload;
            // 业务处理…
            // 若 enable.auto.commit=0，处理后手动提交：
            // $consumer->commit($message);
            break;
        case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            // 分区暂无更多消息
            break;
        case RD_KAFKA_RESP_ERR__TIMED_OUT:
            // 本轮超时，继续循环
            break;
        default:
            // 其它错误
            break;
    }
}
```

要点：

- 必须先 `setGroupId`，`subject()` / `consume()` 时才会 `subscribe`
- `commit($message)` **仅在** `enable.auto.commit` 为空/关闭时真正手动提交
- Rebalance 默认处理 `ASSIGN_PARTITIONS` / `REVOKE_PARTITIONS`

---

## swoolefy 组件注册

`Config/KafkaConfig.php` + `Config/component/kafka.php`：

```php
'kafka_topic_order_group1_producer' => function () use ($dc) {
    $kafkaConf = KafkaConfig::KAFKA_TOPICS[KafkaConfig::KAFKA_TOPIC_ORDER1];
    $producer = new \Swoolefy\Library\Kafka\Producer(
        $dc['kafka_broker_list'],
        $kafkaConf['topic_name']
    );
    $producer->setGlobalProperty($kafkaConf['producer_global_property']);
    $producer->setTopicProperty($kafkaConf['producer_topic_property']);
    return $producer;
},

'kafka_topic_order_group1_consumer' => function () use ($dc) {
    $kafkaConf = KafkaConfig::KAFKA_TOPICS[KafkaConfig::KAFKA_TOPIC_ORDER1];
    $consumer = new \Swoolefy\Library\Kafka\Consumer(
        $dc['kafka_broker_list'],
        $kafkaConf['topic_name']
    );
    $consumer->setGroupId($kafkaConf['group_id']);
    $consumer->setGlobalProperty($kafkaConf['consumer_global_property']);
    $consumer->setTopicProperty($kafkaConf['consumer_topic_property']);
    return $consumer;
},
```

进程示例：

- 生产：`Test/Process/Kafka/ProducerKafka.php`
- 消费：`Test/Process/Kafka/ConsumerKafka.php`

在 `Event.php` 中 `ProcessManager::addProcess(...)` 启用（默认注释）。

独立脚本：`library/tests/Kafka/Producer.php`、`Consumer.php`；环境说明见同目录历史 `readme.txt`。

---

## 公共 API（AbstractKafka）

| 方法 | 说明 |
|------|------|
| `setBrokerList($list)` | 字符串或数组，写入 `metadata.broker.list` |
| `setGlobalProperty(array)` | 合并默认全局配置后 `Conf::set` |
| `setTopicProperty(array)` | Topic 级配置 |
| `setTopicName` / `getTopicName` | Topic 名 |
| `getGlobalConf` / `getTopicConf` | 底层 Conf 对象 |
| `setGlobalConf` / `setTopicConf` | 注入自定义 Conf（高级用法） |

---

## 使用建议

1. **消费放自定义进程**：`AbstractProcess` 长循环 `consume`，勿在 HTTP Worker 阻塞。  
2. **同 key 保序**：对需分区有序的业务（如同一订单事件流），`produce` 传入稳定 `key`。  
3. **调大 `max.poll.interval.ms`**：业务处理慢时避免频繁 rebalance。  
4. **手动提交**：关闭 `enable.auto.commit`，处理成功后再 `commit($message)`，实现至少一次语义下的可控位点。  
5. **与 Amqp / Queues**：跨语言、高吞吐日志流优先 Kafka；简单 Redis 队列见 `Queues`，AMQP 见 `Amqp`。

---

## 快速对照

```php
// 生产
$p = new Producer($brokers, 'topicOrder1');
$p->produce('payload', 5000, 'order-1001');

// 消费
$c = new Consumer($brokers, 'topicOrder1');
$c->setGroupId('topic_order_group1');
$msg = $c->consume(1000);
if ($msg->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
    // handle $msg->payload
}
```
