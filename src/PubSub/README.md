# PubSub

Redis 发布订阅门面（实现于 **bingcool/library**）：`phpredis` 与 `predis` 两套驱动共用同一套会话所有权与派发语义，业务只写一个回调。

设计背景与缺陷溯源见 [docs/library-6.x-PubSub-审查报告.md](../../docs/library-6.x-PubSub-审查报告.md)，回归用例见 [tests/PubSub/PubSubModuleTest.php](../../tests/PubSub/PubSubModuleTest.php)。

---

## 三条契约

订阅连接是**独占**的：进入订阅态后，这条 Redis 连接只允许 `(P)SUBSCRIBE / (P)UNSUBSCRIBE / SSUBSCRIBE / PING / QUIT / RESET`，其他命令会被服务端拒绝并让响应流错位。模块把所有相关约束收口在 `AbstractPubSub` 里。

| 契约 | 含义 | 违约后果 |
|------|------|----------|
| 连接不下发 | 回调只拿 `channel/payload`（模式订阅多一个 `pattern`），拿不到 Redis 连接 | 需要读写 Redis 请另建一个连接 |
| 一连接一会话 | 同一实例的订阅循环运行时，禁止再次 `subscribe()`，也禁止 `publish()` | `SubscriptionConflictException` |
| 退订只回到循环上下文 | `unsubscribe()/punsubscribe()` 必须由订阅循环所在的协程调用 | `SubscriptionConflictException`；跨协程停止请用 `stop()` |

`publish()` 属于「空闲连接」的能力：用另一个连接（或另一个 `PubSub` 实例）发布，返回收到消息的订阅端数量。

---

## 在 Swoolefy 中使用

### 1. 非协程订阅（CLI / 脚本）

```php
use Swoolefy\Library\PubSub\RedisPubSub;
use Swoolefy\Library\Redis\Redis;

$connection = (new Redis())->connect('127.0.0.1', 6379, 2.0);
$pubSub = new RedisPubSub($connection);

$pubSub->subscribe(['order.created'], function (string $channel, string $payload): ?bool {
    // 返回 false 结束循环；返回 null / true 继续
    return $payload === 'shutdown' ? false : null;
});
```

模式订阅多一个 `pattern` 参数：

```php
$pubSub->psubscribe(['order.*'], function (string $pattern, string $channel, string $payload): void {
    // ...
});
```

### 2. 协程订阅（swoolefy Worker 内）

```php
use Swoolefy\Library\PubSub\AbstractPubSub;
use Swoolefy\Library\Redis\Redis;

goApp(function (): void {
    // 连接必须在协程内创建：Swoole 的 stream hook 只作用于之后建立的 socket
    $connection = (new Redis())->connect(env('REDIS_HOST'), 6379, 2.0);
    $pubSub = new RedisPubSub($connection, [
        'dispatch' => AbstractPubSub::DISPATCH_COROUTINE,
        'max_concurrency' => 16,
        'on_error' => static function (\Throwable $e, array $context): void {
            logger()->error('pubsub handler failed', ['channel' => $context['channel'], 'err' => $e->getMessage()]);
        },
    ]);

    $pubSub->subscribe(['order.created'], static function (string $channel, string $payload): void {
        // 每条消息一个协程，并发受 max_concurrency 限制（令牌用满时订阅循环挂起 = 背压）
        handle($payload);
    });
});
```

发布方用另一个连接：

```php
$publisher = (new Redis())->connect(env('REDIS_HOST'), 6379, 2.0);
$receivers = (new RedisPubSub($publisher))->publish('order.created', json_encode($order));
```

### 3. predis 驱动

```php
use Swoolefy\Library\PubSub\PredisPubSub;
use Swoolefy\Library\Redis\Predis;

$connection = new Predis(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379]);
$pubSub = new PredisPubSub($connection);
```

构造时会把 `read_write_timeout` 设为 `-1`（缺省时读取沿用 `default_socket_timeout`，空闲订阅会被判死）；改动通过 `Predis::applyParameters()` 重建 Client，沿用库内既有的重连语义。

---

## 选项

| 选项 | 默认 | 说明 |
|------|------|------|
| `dispatch` | `inline` | `inline` 在循环上下文串行执行回调；`coroutine` 每条消息一个协程，仅 Swoole 可用 |
| `max_concurrency` | `64` | `coroutine` 派发下的并发上限（1..65536），令牌满时订阅循环挂起形成背压 |
| `on_error` | `null` | 回调异常的观测钩子 `fn(\Throwable $e, array $context): void`，配置后循环继续投递 |
| `coroutine_launcher` | 自动 | 覆盖协程启动器；默认优先 `goApp()`（swoolefy），其次 `Swoole\Coroutine::create` |

派发失败的处理策略只有一条：**不静默丢消息**。

- 配了 `on_error`：计入 `stats()['failed']`，循环继续。
- 没配：记录首个异常并请求停止，循环退出后由 `subscribe()` 重新抛出（phpredis 会吞掉回调里抛出的异常，所以不能在回调里直接抛）。

---

## 停止与状态

| API | 用途 |
|-----|------|
| 回调返回 `false` | 内联派发的常规停止；驱动随后退订并结束循环 |
| `unsubscribe()` / `punsubscribe()` | 循环内退订部分渠道，退订确认会被读完，不留响应残留 |
| `stop()` | 任意上下文可调用，只登记请求、不碰 socket；下一条到达的消息负责唤醒循环结束 |
| `isRunning()` / `isSubscribed()` | 会话状态 |
| `subscribedChannels()` / `subscribedPatterns()` | 仅在循环内有值，循环结束后自动清空 |
| `stats()` | `received / dispatched / completed / failed / in_flight / running / channels / patterns` |

`stop()` 空闲时不会立即返回——阻塞读必须被一条新消息唤醒。需要「立刻停」请用 `unsubscribe()`（同一协程内）或再发布一条消息。

---

## 已知陷阱（都已在测试里断言）

1. **回调返回 `false` 不等于优雅停止**：phpredis 会直接断开连接并抛 `RedisException: read error on connection`（非协程下 exit 255）。驱动改为在回调内显式退订。
2. **phpredis 吞掉回调里的异常**：`\Throwable` 从回调抛出后循环继续且零输出。因此异常先记录、循环退出后再重抛。
3. **跨协程读写订阅 socket 是致命错误**：`Swoole\Error: Socket#N has already been bound to another coroutine` 无法被 `try/catch` 捕获（进程直接 255）。模块用 cid 比对提前拒绝。
4. **退订确认没人读会整体错位一条**：predis 的 `Consumer::unsubscribe()` 只写不读，驱动随后在同一连接上读完确认（期间到达的业务消息照常派发）。
5. **全量退订后 Consumer 自毁**：predis 的 `Consumer` 在订阅数归零时 `invalidate()` 且永不复位，驱动丢弃并重建，否则重订阅只写命令、循环一次都不进。
6. **协程内必须用协程内建立的连接**：hook 只作用于之后创建的 socket，否则订阅的阻塞读会占死事件循环；`hook_flags` 未含 `SWOOLE_HOOK_TCP` 时 `subscribe()` 直接抛 `InvalidSubscriptionException`。

---

## 异常

| 异常 | 触发 |
|------|------|
| `InvalidSubscriptionException` | 渠道/模式为空或非字符串、`dispatch`/`max_concurrency` 非法、非协程用 `coroutine`、无会话退订、hook 未开启 |
| `SubscriptionConflictException` | 循环已运行时再次订阅、订阅态 `publish()`、跨协程退订 |
| `PubSubException` | 驱动/连接类型错配、`publish()` 返回 `false` |

三者同属 `Swoolefy\Library\PubSub\Exception`，均继承 `\RuntimeException`；后两者是 `final` 并继承 `PubSubException`，所以业务侧 `catch (PubSubException)` 能覆盖本模块全部异常。

---

## 测试

```bash
# 容器内（swoolefy-php85-v62），Redis 走宿主机映射
docker exec -w /home/wwwroot/library -e REDIS_HOST=host.docker.internal swoolefy-php85-v62 \
  ./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/PubSub
```

缺 `ext-redis` / `predis` / `ext-swoole` 或 Redis 不可达时按 skip 处理，不会红灯。
