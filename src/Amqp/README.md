# Amqp

基于 [php-amqplib](https://github.com/php-amqplib/php-amqplib) 的 RabbitMQ 封装：统一连接工厂、交换机/队列声明，以及 Direct / Fanout / Topic 与基于死信的延迟队列的发布与消费。

命名空间：`Swoolefy\Library\Amqp`

---

## 特性

| 能力 | 说明 |
|------|------|
| 连接工厂 | `AmqpStreamConnectionFactory` 支持多 host 依次尝试 |
| 交换模式 | Direct / Fanout / Topic |
| 延迟队列 | `AmqpDelayDirectQueue` / `AmqpDelayTopicQueue`（TTL + 死信转发） |
| 发布确认 | `setAckHandler` / `setNackHandler` + `confirm_select` |
| 消费循环 | `consumer` / `consumerWithTime`，断线重连、可选 PCNTL heartbeat |
| 优先级等 | 通过 `AmqpConfig::$arguments` 传递（如 `x-max-priority`、`x-message-ttl`） |

---

## 目录结构

```
Amqp/
├── AmqpStreamConnectionFactory.php  # 连接创建
├── AmqpConfig.php                   # exchange / queue / routing 配置对象
├── AmqpAbstract.php                 # 发布/消费抽象
├── AmqpTopicAbstract.php
├── AmqpDirectQueue.php              # Direct
├── AmqpFanoutQueue.php              # Fanout 广播
├── AmqpTopicQueue.php               # Topic（publish 需传 routingKey）
├── AmqpDelayDirectQueue.php         # Direct 延迟（死信）
├── AmqpDelayTopicQueue.php          # Topic 延迟（死信）
├── AmqpTrait.php                    # 声明、ack、close
├── AmqpConsumerTrait.php            # 普通消费循环
├── AmqpDelayPublishTrait.php
├── AmqpDelayConsumerTrait.php       # 延迟队列消费（消费死信侧）
└── README.md
```

---

## 交换模式对照

| 类 | 模式 | routing / binding |
|----|------|-------------------|
| `AmqpDirectQueue` | Direct | `routingKey` 必须等于 `bindingKey`，精准投递 |
| `AmqpFanoutQueue` | Fanout | 忽略路由键，广播到所有绑定队列 |
| `AmqpTopicQueue` | Topic | 绑定用 pattern（如 `orderSaveEvent.*`），`publish($msg, $routingKey)` |
| `AmqpDelayDirectQueue` | Direct + DLX | 消息先进延迟队列，TTL 后进死信队列再消费 |
| `AmqpDelayTopicQueue` | Topic + DLX | 同上，Topic 语义 |

延迟原理：业务队列设置 `x-dead-letter-exchange` / `x-dead-letter-queue`（及 `x-message-ttl`），到期未消费则转发到死信队列；消费者应消费死信侧队列。

---

## 连接配置

### 工厂

```php
use Swoolefy\Library\Amqp\AmqpStreamConnectionFactory;

$connection = AmqpStreamConnectionFactory::create(
    [
        [
            'host'     => '127.0.0.1',
            'port'     => 5672,
            'user'     => 'guest',
            'password' => 'guest',
            'vhost'    => '/',
        ],
        // 可配置多个 host，前一个失败则尝试下一个
    ],
    [
        'connection_timeout'  => 5.0,
        'read_write_timeout'  => 5.0,
        'keepalive'           => false,
        'heartbeat'           => 0,
        // 'insist' | 'is_lazy' | 'io_type' | 'login_method' | …
    ]
);
```

`host` / `port` / `user` / `password` 必填；`vhost` 默认 `/`。

### swoolefy 组件示例

`App/Config/component/amqp.php`：

```php
'amqpConnection' => function () use ($dc) {
    return AmqpStreamConnectionFactory::create(
        $dc['amqp_connection']['host_list'],
        $dc['amqp_connection']['options']
    );
},

'orderAddDirectQueue' => function () {
    $connection = Application::getApp()->get('amqpConnection');
    $amqpConfig = new \Swoolefy\Library\Amqp\AmqpConfig();
    $amqpConfig->exchangeName = 'order_exchange_direct';
    $amqpConfig->queueName    = 'order_add_queue_direct';
    $amqpConfig->type         = \PhpAmqpLib\Exchange\AMQPExchangeType::DIRECT;
    $amqpConfig->bindingKey   = 'order-direct-add';
    $amqpConfig->routingKey   = 'order-direct-add'; // Direct 必须与 bindingKey 一致
    $amqpConfig->durable      = true;
    $amqpConfig->arguments    = ['x-max-priority' => 10];

    $queue = new \Swoolefy\Library\Amqp\AmqpDirectQueue($connection, $amqpConfig);
    $queue->setAckHandler(function (\PhpAmqpLib\Message\AMQPMessage $message) {
        // publisher confirm
    });
    return $queue;
},
```

完整示例见：`swoolefy/Test/Config/AmqpConfig.php`、`Test/Config/component/amqp.php`。

---

## AmqpConfig 字段

| 字段 | 说明 |
|------|------|
| `exchangeName` / `queueName` | 交换机 / 队列名 |
| `type` | `direct` / `fanout` / `topic` 等 |
| `bindingKey` / `routingKey` | 绑定键 / 路由键（Direct 须相等） |
| `durable` | 持久化 |
| `passive` / `exclusive` / `autoDelete` / `internal` / `nowait` | 声明参数 |
| `consumerTag` | 消费者标签，多进程建议唯一（如加 pid） |
| `arguments` | 扩展参数：`x-max-priority`、`x-message-ttl`、`x-dead-letter-*` 等 |
| `ticket` | AMQP ticket |

---

## 发布消息

### Direct

```php
use PhpAmqpLib\Message\AMQPMessage;
use Swoolefy\Core\Application;

/** @var \Swoolefy\Library\Amqp\AmqpDirectQueue $queue */
$queue = Application::getApp()->get('orderAddDirectQueue');

$message = new AMQPMessage(
    'hello ' . time(),
    [
        'content_type'  => 'text/plain',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        // 'priority'   => 5,  // 需队列开启 x-max-priority
    ]
);

$queue->publish($message);
```

### Topic（必须传 routingKey）

```php
/** @var \Swoolefy\Library\Amqp\AmqpTopicQueue $topic */
$topic = Application::getApp()->get('orderAddTopicQueue');
$topic->publish($message, 'orderSaveEvent.send');
```

### 延迟 Direct / Topic

```php
/** @var \Swoolefy\Library\Amqp\AmqpDelayDirectQueue $delay */
$delay = Application::getApp()->get('orderDelayDirectQueue');
$delay->publish($message);

/** @var \Swoolefy\Library\Amqp\AmqpDelayTopicQueue $delayTopic */
$delayTopic = Application::getApp()->get('orderDelayTopicQueue');
$delayTopic->publish($message, 'orderSaveEvent.send');
```

延迟队列 `arguments` 示例：

```php
'arguments' => [
    'x-dead-letter-exchange' => 'common_dlx_exchange',
    'x-dead-letter-queue'    => 'order_add_queue_delay_direct_dlx',
    'x-message-ttl'          => 3000, // ms
    'x-max-priority'         => 10,
],
```

消息级延迟还可在 `AMQPMessage` 上设 `expiration`（毫秒字符串），与队列 TTL 配合使用。

### HTTP 试调（Test 应用）

```bash
curl -X GET 'http://127.0.0.1:9501/api/amqp/publish'
curl -X GET 'http://127.0.0.1:9501/api/amqp/publish-delay-topic'
curl -X GET 'http://127.0.0.1:9501/api/amqp/publish-delay-direct'
```

对应控制器：`Test/Controller/AmqpController.php`。

---

## 消费消息

建议放在 **自定义进程**（`AbstractProcess`）中长驻，而不是 HTTP Worker 内阻塞。

```php
/** @var \Swoolefy\Library\Amqp\AmqpDirectQueue $queue */
$queue = Application::getApp()->get('orderAddDirectQueue');

$queue->setConsumerExceptionHandler(function (\Throwable $e) {
    // 记录异常，循环会 close 后重试
});

// 默认 wait 间隙约 0.01s
$queue->consumer(function (\PhpAmqpLib\Message\AMQPMessage $message) {
    echo $message->body, PHP_EOL;
    $message->ack(); // noAck=false 时需手动确认
});

// 或自定义休眠间隔（秒，小于 1 用 usleep）
$queue->consumerWithTime(function ($message) {
    $message->ack();
}, 0.1);
```

消费行为要点：

- QoS：`basic_qos(0, 1, false)`（每次预取 1 条）
- 断线：`reconnect()` 并重建 channel
- `heartbeat > 0` 时注册 `PCNTLHeartbeatSender`（适合 CLI/进程，注意与协程环境兼容性）
- 延迟队列消费走 `AmqpDelayConsumerTrait`：声明并消费死信侧

进程示例：`Test/Process/AmqpProcess/AmqpConsumer.php`（在 `Event.php` 中 `addProcess` 启用）。

---

## Publisher Confirm

```php
$queue->setAckHandler(function (AMQPMessage $message) {
    // broker 已确认
});
$queue->setNackHandler(function (AMQPMessage $message) {
    // broker 拒绝 / 未确认
});
$queue->publish($message); // 内部 confirm_select + wait_for_pending_acks
```

---

## Fanout 注意点

- 发布侧组件可只配 `exchangeName`（广播到该交换机下所有绑定队列）
- 每个消费进程绑定各自的 `queueName` + 唯一 `consumerTag`
- `binding_key` / `routing_key` 通常置空

---

## 使用建议

1. **连接复用**：`amqpConnection` 作单例组件；各队列组件共享同一连接、各自 channel。  
2. **consumerTag**：多进程消费同一队列时加 pid / 唯一后缀，避免冲突。  
3. **Direct**：`routingKey === bindingKey`，否则抛 `AmqpException`。  
4. **持久化**：队列/交换机 `durable=true`，消息 `DELIVERY_MODE_PERSISTENT`。  
5. **消费进程**：在 `Event::onInit` / 进程管理里拉起，HTTP 接口只做 `publish`。  
6. **延迟消费**：消费 `AmqpDelay*` 时实际监听的是死信队列，勿与业务队列混用。

---

## 依赖

- `php-amqplib/php-amqplib`
- RabbitMQ（或兼容 AMQP 0-9-1 Broker）

---

## 快速对照

```php
// 连接
$conn = AmqpStreamConnectionFactory::create($hosts, $options);

// Direct 发布
(new AmqpDirectQueue($conn, $config))->publish($message);

// Topic 发布
(new AmqpTopicQueue($conn, $config))->publish($message, 'order.save');

// 延迟 Direct
(new AmqpDelayDirectQueue($conn, $config))->publish($message);

// 消费（进程内）
$queue->consumerWithTime($callback, 0.1);
```
