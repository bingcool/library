# Queues

基于 Redis 的队列组件，提供两类能力：

1. **即时队列 `Queue`**：List + 优先队列 + 失败延迟重试（ZSET）
2. **延迟队列 `RedisDelayQueue` / `PredisDelayQueue`**：ZSET 按到期时间弹出，Lua 原子取出并删除

命名空间：`Swoolefy\Library\Queues`

---

## 目录结构

```
Queues/
├── Queue.php                          # 即时队列（List）
├── BaseDelayQueue.php                 # 延迟队列基类（ZSET）
├── RedisDelayQueue.php                # phpredis 驱动
├── PredisDelayQueue.php               # Predis 驱动
├── LuaScripts.php                     # 原子脚本
├── Interfaces/AbstractDelayQueueInterface.php
└── README.md
```

---

## 两类队列对比

| | `Queue` | `RedisDelayQueue` / `PredisDelayQueue` |
|--|---------|----------------------------------------|
| 存储 | List + 优先 List + 重试 ZSET | 单一 ZSET |
| 入队 | `push` / `pushPriority`（立即） | `addItem($data, $delaySeconds)` + `push` |
| 出队 | `pop($timeout)`（BRPOP） | `pop()`（到期 score ≤ now） |
| 重试 | `retry($data, $delay)` → 重试 ZSET，下次 `pop` 时回流主队列 | `retry($member, $delay)` → 改写 score 再入 ZSET |
| 驱动 | `Redis` 或 `Predis` | 分别用对应类，勿混用 |

---

## 即时队列 `Queue`

### Redis Key

以构造参数 `$queueKey` 为前缀：

| Key | 用途 |
|-----|------|
| `{queueKey}` | 主队列 List |
| `{queueKey}:priority_queue` | 优先队列 List（`pop` 时优先 BRPOP） |
| `{queueKey}:retry_queue_sort` | 失败重试 ZSET（score = 下次可执行时间） |

### 消息元字段

`push` / `pushPriority` 时自动注入：

```php
[
    // ...业务字段
    '__id'         => 'md5...',   // 消息唯一 ID
    '__retry_num'  => 0,          // 已重试次数
    '__timestamp'  => time(),     // 入队时间
]
```

### 用法

```php
use Swoolefy\Library\Queues\Queue;
use Swoolefy\Core\Application;

$redis = Application::getApp()->get('redis');
// 或 Predis：$predis = Application::getApp()->get('predis')->getObject();

$queue = new Queue($redis, 'queue:order:list');
$queue->setRetryTimes(3); // 默认 3

// 入队（可变参数，多个数组一次推入）
$queue->push(['order_id' => 1001], ['order_id' => 1002]);

// 优先入队
$queue->pushPriority(['order_id' => 9999]);

// 出队：先看优先队列（timeout=1），再看主队列（timeout 限制在 1~3 秒）
// 每次 pop 会用 Lua 把重试 ZSET 中已到期的消息回流到主队列（最多 100 条）
$result = $queue->pop(2);
// phpredis: [queueKey, payloadArray]
// Predis: 类似；无数据时可能为 []

if (!empty($result[1])) {
    $data = $result[1];
    try {
        // 业务处理…
    } catch (\Throwable $e) {
        // 延迟 10 秒后重试；达到 setRetryTimes 上限则丢弃
        $queue->retry($data, 10);
    }
}

$len = $queue->count(); // 主队列 LLEN
```

### swoolefy 组件示例

```php
// Config/component/queue.php
'queue' => function () {
    $predis = Application::getApp()->get('predis');
    return new \Swoolefy\Library\Queues\Queue($predis, 'queue:order:list');
},
```

HTTP 试调：`GET /api/queue/push` → `Test\Controller\QueueController`。

---

## 延迟队列

### 原理

- Member：JSON 消息（含 `__id` / `__retry_num` / `__timestamp`）
- Score：`time() + $delayTime`（到期时间戳）
- `pop` / `rangeByScore`：Lua `ZRANGEBYSCORE` + `ZREM`，原子弹出，避免多消费者重复取

### Redis vs Predis

```php
// phpredis
$queue = new \Swoolefy\Library\Queues\RedisDelayQueue($redis, 'queue:order:delay');

// Predis（不要把 Predis 传给 RedisDelayQueue，会抛 QueueException）
$queue = new \Swoolefy\Library\Queues\PredisDelayQueue($predis, 'queue:order:delay');
```

### 用法

```php
$delay = Application::getApp()->get('delayQueue');

// 链式批量添加；满 200 条会自动 flush；也可手动 push / 析构时 flush
$delay
    ->addItem(['order_id' => 1111], 2)   // 2 秒后到期
    ->addItem(['order_id' => 2222], 5)
    ->push();

while (true) {
    // 默认 limit [0, 9]，取最多 10 条已到期消息
    $items = $delay->pop(['limit' => [0, 9]]);
    foreach ($items as $item) {
        try {
            // 处理 $item（已 json_decode 为数组）
        } catch (\Throwable $e) {
            $delay->retry($item, 5); // 5 秒后再试；超次数则忽略
        }
    }
    sleep(1);
}
```

### 其它 ZSET 操作（基类）

| 方法 | 说明 |
|------|------|
| `count($start, $end)` | `ZCOUNT` |
| `range($start, $end, $withScores)` | `ZRANGE` |
| `rem($members)` / `remRangeByScore` | 删除 |
| `incrBy($increment, $member)` | `ZINCRBY` |
| `getDelayKey()` / `getRedis()` | 元信息 |

进程示例：`Test/Process/QueueProcess/Queue.php`。

---

## Lua 脚本（`LuaScripts`）

| 方法 | 作用 |
|------|------|
| `getQueueLuaScript()` | 重试 ZSET 到期项 → `LPUSH` 主队列并删除 |
| `getQueueRetryLuaScript()` | 失败消息 `ZADD` 进重试 ZSET |
| `getRangeByScoreLuaScript()` | 延迟队列按 score 取出并 `ZREM` |
| `getDelayRetryLuaScript()` | 延迟队列重试：按新 score 再 `ZADD` |

保证多进程/多协程下「取消息」与「删消息」原子性。

---

## 组件注册（推荐）

```php
return [
    'queue' => function () {
        $redis = Application::getApp()->get('redis');
        return new \Swoolefy\Library\Queues\Queue($redis, 'queue:order:list');
    },
    'delayQueue' => function () {
        $redis = Application::getApp()->get('redis');
        return new \Swoolefy\Library\Queues\RedisDelayQueue($redis, 'queue:order:delay');
    },
];
```

消费建议放在 **自定义进程**（`AbstractProcess`）循环中，HTTP Worker 只负责 `push` / `addItem`。

---

## 注意点

1. **驱动匹配**：`RedisDelayQueue` 仅接受 phpredis；`PredisDelayQueue` 用 Predis。`Queue` 两种都可。  
2. **`pop` 超时**：`Queue::pop` 的 `$timeOut` 会被钳制到 `[1, 3]` 秒。  
3. **重试上限**：默认 3 次（`setRetryTimes`）；达到上限后 `retry` 直接返回，消息不会再入队。  
4. **延迟批量**：`addItem` 缓存本地，≥200 条或 `__destruct` / 显式 `push()` 才写入 Redis。  
5. **协程**：请使用框架组件容器拿到的 Redis 连接，避免跨协程共用同一连接实例。  
6. **与 Amqp 区别**：本模块是 Redis 轻量队列；跨服务、复杂路由请用 `Amqp` 模块。

---

## 快速对照

```php
// 即时
$q = new Queue($redis, 'q:demo');
$q->push(['id' => 1]);
[$key, $data] = $q->pop(1) + [null, null];
$q->retry($data, 10);

// 延迟
$d = new RedisDelayQueue($redis, 'q:delay');
$d->addItem(['id' => 1], 30)->push();
$items = $d->pop();
```
