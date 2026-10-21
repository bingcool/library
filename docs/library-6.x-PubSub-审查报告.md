# library-6.x PubSub 模块审查报告

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块 | PubSub（`src/PubSub/**/*.php`） |
| 审查日期 | 2026-10-20 |
| 审查范围 | 3 个 PHP 文件、314 行（`AbstractPubSub.php` 71 行、`PredisPubSub.php` 132 行、`RedisPubSub.php` 111 行）；`tests/PubSub/Subscribe.php` 47 行、`tests/PubSub/Psubscribe.php` 39 行 |
| 关联依赖 | `Swoolefy\Library\Redis\RedisConnection` / `Redis` / `Predis`（`src/Redis/**`）、`RedisRetryPolicy`；`predis/predis` `~3.4`（`composer.json:28`，实际 **v3.7.0**）；phpredis 扩展 |
| 运行环境 | 宿主机 PHP 8.4.14 / Swoole 6.1.7 / phpredis 已加载；容器 `swoolefy-php85-v62`（镜像 `swoolefy-php85-swoole62:v1`）PHP 8.5.8 / Swoole 6.2.2；Redis 服务端 `redis8.6.4`（127.0.0.1:6379） |
| 运行前提 | **library 专供 swoolefy 框架使用**，协程派发依赖 swoolefy 全局函数 `goApp()`（`swoolefy/src/Core/Func/function.php:192`）。本报告一律在「`goApp()` 存在」的前提下定级；复现脚本中定义的 `goApp()` 替身仅用于隔离驱动行为，不构成缺陷依据 |
| 测试基线 | 审查时 `tests/PubSub/` 下**没有任何 PHPUnit 用例**（`tests/phpunit.xml` 只收集 `*Test.php`），仅两个 `while (true)` 手工演示脚本；本次审查未运行仓库测试套件，改用定向复现。**修复后基线**：`tests/PubSub/PubSubModuleTest.php` 43 用例 / 133 断言在容器内 `host.docker.internal:6379` 真实 Redis 上连跑三次全绿（≈14.7 s），`tests/Redis` 25 用例同步复跑无回归 |
| 审查方式 | 逐文件通读 + Predis 3.7.0 vendor 源码对照 + 真实 Redis 端到端复现（19 个订阅端脚本 + 1 个发布端脚本）+ Swoole 协程 hook 实测 |
| 源码变更 | 审查阶段无改动。2026-10-20 追加修复 PUB-07（`PredisPubSub.php:15` 失效 import 命名空间）。**2026-10-21 在容器 `swoolefy-php85-v62` 内完成 PUB-01~PUB-11 全部修复并回归通过**：`AbstractPubSub.php` 重写为「订阅会话 + 派发策略」抽象层（538 行），两驱动只做协议适配（148 / 168 行），新增 3 个领域异常、`src/PubSub/README.md`（155 行）与 `tests/PubSub/PubSubModuleTest.php`（832 行、43 用例），删除两个 `while (true)` 演示脚本 |
| 复现脚本 | `/tmp/pubsub-probe/*.php`（临时脚本，未纳入仓库），发布端统一为 `/tmp/pubsub-probe/publisher.php <channel> <delay> <message>` |

**结论摘要**

- **P0：0 项**。模块不涉及鉴权、文件写入、反序列化与注入面，未发现可导致代码执行或凭据泄露的缺陷。
- **P1：3 项**。一个跨协程致命缺陷 + 两个 Predis 订阅会话生命周期缺陷：
  1. 协程模式下回调被 `goApp()` 放进**新协程**，而回调参数又拿到订阅连接本身；在回调里执行任何需要读响应的命令（或 phpredis 的 `unsubscribe()`）会触发 Swoole 跨协程 socket 冲突，**进程以 exit 255 崩溃**。两种驱动均复现。
  2. `PredisPubSub::unsubscribe()` / `punsubscribe()` 不复用活动 consumer，而是 `pubSubLoop()` 新建一个，退订确认响应无人读取 → 共享连接的响应**整体错位一条**。
  3. 全部退订后缓存的 Predis consumer 已被 `invalidate()`，`subscribe()` 仍复用该失效对象：写入 `SUBSCRIBE` 后 `foreach` 立刻退出，**重订阅静默失效且残留响应继续错位**。
- **P2：8 项**。Predis 未关闭读超时、`OPT_READ_TIMEOUT` 永久污染调用方连接、回调参数类型两驱动不一致、失效的 `Predis\PubSub\Consumer` import（**已于 2026-10-20 修正，见 PUB-07 修复状态**）、订阅态下 `publish()` 失败无契约说明、空渠道数组行为两驱动分裂、每条消息一个协程无背压且派发失败静默丢消息、零自动化测试。
- 本文所有「已复现」结论均来自当前 `src/PubSub` 代码 + Predis 3.7.0 在真实 Redis 上的实际行为，不是静态猜测；仅有代码依据的条目在「核实结果」列明确标注。
- **修复进度（2026-10-21）**：P1 3 项 + P2 8 项（共 11 项）已全部修复并在容器内回归通过，落地方式见 §7「修复落地记录」，条目↔用例映射见 §8。

---

## 2. 问题总览

| 等级 | ID | 问题 | 位置 | 核实结果 | 状态 |
|---|---|---|---|---|---|
| P0 | — | 未确认 P0 级问题 | — | 见 §3 | — |
| P1 | PUB-01 | 协程模式下回调在新协程中使用**订阅连接**（含 `unsubscribe()`）→ `Swoole\Error` 跨协程 socket 冲突，进程崩溃 exit 255 | `RedisPubSub.php:63-67`、`:99-102`、`PredisPubSub.php:90-98` | 已复现（两驱动） | 已修复 |
| P1 | PUB-02 | `PredisPubSub::unsubscribe()/punsubscribe()` 新建 consumer 而非复用活动 consumer，退订确认无人读取，后续命令响应整体错位一条 | `PredisPubSub.php:118-132` | 已复现 | 已修复 |
| P1 | PUB-03 | 全量退订后缓存的 consumer 已失效，重订阅写入 `SUBSCRIBE` 但 `foreach` 立即返回，回调一次都不执行且残留响应污染连接 | `PredisPubSub.php:77`、`:105-112`；Predis `Consumer.php:265-280`、`:306-308` | 已复现 | 已修复 |
| P2 | PUB-04 | Predis 未关闭读超时，非协程环境空闲 1 秒（显式）/ 60 秒（`default_socket_timeout`）后抛 `Predis\TimeoutException`，订阅终止；`RedisPubSub` 有 `-1` 处理，Predis 路径缺失 | `PredisPubSub.php:34-37`；`RedisPubSub.php:32` | 已复现 | 已修复 |
| P2 | PUB-05 | 构造函数把调用方连接的 `OPT_READ_TIMEOUT` 由 `0.0` 永久改成 `-1.0`，从不恢复；连接若来自连接池则被污染 | `RedisPubSub.php:32` | 已复现 | 已修复 |
| P2 | PUB-06 | 回调第一个参数两驱动类型不同（Predis 给 `Swoolefy\Library\Redis\Predis` 包装对象，phpredis 给原生 `\Redis`）；`@var \Predis\Client` 文档与实际不符 | `PredisPubSub.php:20-22`、`:91`、`:96`；`RedisPubSub.php:63` | 已复现 | 已修复 |
| P2 | PUB-07 | 失效 import：`use Predis\PubSub\Consumer`（3.7.0 已迁到 `Predis\Consumer\PubSub\Consumer`），类不存在，静态分析与 IDE 全部失效 | `PredisPubSub.php:15`、`:25`、`:103` | 已复现（`class_exists` 为 false） | 已修复 |
| P2 | PUB-08 | 订阅态下 `publish()` 必然失败：Predis 抛 `ServerException`，phpredis 返回 `false`（与 `@return int` 契约不符），两处都无守卫与文档说明 | `PredisPubSub.php:44-47`；`RedisPubSub.php:40-43` | 已复现 | 已修复 |
| P2 | PUB-09 | 空渠道/空模式无校验：Predis 抛裸 `Error: Cannot use object of type Predis\Response\Error as array`，phpredis 直接返回不做任何事 | `PredisPubSub.php:79-82`；`RedisPubSub.php:60-73` | 已复现 | 已修复 |
| P2 | PUB-10 | 每条消息一个 `goApp()` 协程：无并发上限、无背压、消息乱序处理；swoolefy `goApp` 吞掉回调异常，订阅循环对失败无感知 | `RedisPubSub.php:63-67`、`:82-92`；`PredisPubSub.php:90-98` | 代码 + vendor 确认 | 已修复 |
| P2 | PUB-11 | 无任何 PHPUnit 用例，`tests/PubSub/` 只有两个 `while (true)` 死循环脚本，无法进入 CI；模块亦无 README/设计文档 | `tests/PubSub/*.php`、`src/PubSub/` | 已确认（`tests/phpunit.xml` 不收集） | 已修复 |

> 另有 1 项设计观察未计入问题清单：`AbstractPubSub::isCoroutine()` 在构造时快照协程上下文（`AbstractPubSub.php:31-35`、`:49-61`）。跨上下文复用同一个 PubSub 对象（Worker 启动时构造、协程内使用，或反之）会静默切换回调执行模型。鉴于修复 PUB-01 时该判断大概率会被重新设计，单列价值有限，故并入 §7 修复路线一并处理。

**修复状态（2026-10-21）**：PUB-01 ~ PUB-11 全部修复完毕，容器内 43 个用例回归通过。落地方式见 §7「修复落地记录」，条目到用例的映射见 §8。构造期协程快照（上面那条观察）已随重构删除，派发模型改由 `dispatch` 选项显式决定。

---

## 3. P0 结论

本次审查**未确认 P0 级问题**，判断依据：

1. **无外部输入进入危险原语**。渠道名、模式名、消息体只作为 Redis 命令参数交给驱动，不存在 SQL/文件路径/反序列化/`eval` 拼接。
2. **无凭据或敏感数据落盘**。PubSub 不接触密码；日志由 `RedisConnection::log()` 记录方法名与参数，渠道名与消息体会进入 `lastLogs`（`RedisConnection.php:125-131`），但 `lastLogs` 只由调用方主动 `getLastLogs()` 读取，模块本身不写日志文件。若消息体承载敏感内容，属于调用方使用问题，不列 P0。
3. **PUB-01 是可用性缺陷而非安全缺陷**：进程崩溃不产生越权、代码执行或数据破坏，因此按功能性最高级 P1 处理。
4. **PUB-02、PUB-03 会造成数据错位但范围可控**：错位发生在**同一个 Redis 连接**上，只影响复用该连接的业务命令，不会跨连接或跨请求扩散，且需要调用 `unsubscribe()` 才触发，故定为 P1 而非 P0。
5. **`goApp()` 依赖不计入问题清单**：library 专供 swoolefy 框架使用，`goApp()` 由框架全局提供，「协程派发依赖该函数」属于设计前提而非缺陷。派发失败/回调异常对订阅循环不可见的问题另见 PUB-10。

---

## 4. P1 问题详情

### PUB-01 协程模式下回调使用订阅连接导致进程崩溃（含 `unsubscribe()`）

**位置**

- `src/PubSub/RedisPubSub.php:63-72`（`handleSubscribe`）、`:82-92`（`psubscribe`）、`:99-111`（`unsubscribe`/`punsubscribe`）
- `src/PubSub/PredisPubSub.php:90-98`
- `src/Redis/Redis.php:150-155`（`__call` → `callWithRetry`）

**代码现状**

```php
// RedisPubSub.php:63-67（协程分支）
$this->redis->subscribe($channels, function ($redis, $chan, $msg) use ($callback) {
   goApp(function () use ($callback, $redis, $chan, $msg) {
       return call_user_func($callback, $redis, $chan, $msg);   // $redis 是正处于订阅态的连接
   });
});
```

回调收到的 `$redis` 就是**当前被 `subscribe()` 阻塞读取的那个连接**，而 `goApp()` 把回调放进了另一个协程。

**根因**

1. 订阅连接在 Swoole 下被绑定到「正在读它的协程」，另一个协程再对该 socket 做读操作即违反 Swoole 的所有权约束。
2. 模块把订阅连接当作回调参数暴露，等于鼓励调用方在另一个协程里使用它。
3. 退订/普通命令与订阅读取共用同一连接，没有控制信道（Channel）与独立连接。

**复现 A：phpredis，协程内从另一个协程退订** `/tmp/pubsub-probe/i_phpredis_unsub_external.php`

```
[cb] hello
[unsub] calling unsubscribe from another coroutine
Fatal error: Uncaught Swoole\Error: Socket#5 has already been bound to another coroutine#1,
reading of the same socket in coroutine#2 at the same time is not allowed
  in /Users/macbook/Documents/wwwphp/library/src/Redis/Redis.php:153
exit=255
```

**复现 B：phpredis，回调（`goApp` 新协程）内退订** `/tmp/pubsub-probe/h_phpredis_blocking.php`

```
[tick] 0  [tick] 1  [tick] 2  [tick] 3
[cb] one
Fatal error: Uncaught Swoole\Error: Socket#5 has already been bound to another coroutine#3,
reading of the same socket in coroutine#1 at the same time is not allowed
  in /Users/macbook/Documents/wwwphp/library/src/Redis/Redis.php:153
exit=255
```

（同一脚本顺带否证了「phpredis 订阅会阻塞整个 Worker」的猜测：`Swoole\Coroutine\System::sleep` 协程在订阅期间正常 tick。）

**复现 C：phpredis，回调内使用传入连接执行普通命令** `/tmp/pubsub-probe/q_callback_uses_connection.php phpredis`

```
[cb] msg=x, redis-arg=Redis
[cb] get =>
Fatal error: Uncaught Swoole\Error: Socket#5 has already been bound to another coroutine#2,
reading of the same socket in coroutine#1 at the same time is not allowed
  in /Users/macbook/Documents/wwwphp/library/src/Redis/Redis.php:153
exit=255
```

**复现 D：Predis 同样崩溃** `/tmp/pubsub-probe/q_callback_uses_connection.php predis`

```
[cb] msg=x, redis-arg=Swoolefy\Library\Redis\Predis
[cb] get =>
Fatal error: Uncaught Swoole\Error: Socket#5 has already been bound to another coroutine#2,
reading of the same socket in coroutine#1 ... 
  in /Users/macbook/Documents/wwwphp/library/vendor/predis/predis/src/Connection/Resource/Stream.php:273
exit=255
```

（栈显示崩溃点在 `PredisPubSub.php:85` 的 `foreach` → `Consumer->current()` → `Stream->read()`，即订阅协程一侧被抢读。）

**对照：非协程模式没有该问题** `/tmp/pubsub-probe/k_phpredis_nocoroutine_cb.php`

```
[cb] nc
[sub] returned normally, count=1     # 回调内 unsubscribe 正常，退出码 0
```

Predis 的 `punsubscribe()` 在协程回调里也「侥幸可用」（`/tmp/pubsub-probe/j_predis_punsub_in_cb.php`，`[loop] returned after 0.49s count=1`），原因是 `Consumer::unsubscribe()` **只写不读**（`vendor/predis/predis/src/Consumer/PubSub/Consumer.php:173-177`），不触发读冲突。也就是说：Predis 路径只有「读响应」会崩，phpredis 连退订都会崩——行为完全依赖驱动内部实现，模块没有给出任何约束。

**影响**

- swoolefy 的默认运行形态就是协程：`RedisPubSub::unsubscribe()` / `punsubscribe()` 在这条路径上**不可用**，直接把进程打挂（`exit 255`）。
- 仓库自带演示脚本 `tests/PubSub/Psubscribe.php` 里那行被注释掉的 `//var_dump($pubSub->punsubscribe(['test1*']));` 正是这个坑的痕迹。
- 回调签名把订阅连接交给业务，业务用它做「回写状态 / 幂等键」等常规操作即崩溃，属于 API 层面的诱导性设计。

**修复建议**

1. **不要让回调持有订阅连接**：回调签名收敛为 `($channel, $payload)`（`psubscribe` 追加 `$pattern`），或注入一个**独立的非订阅连接**用于业务命令。
2. 退订改为「订阅循环自身可控」：`subscribe()` 返回一个句柄（或接受 `\Swoole\Coroutine\Channel` / 取消标志），由循环协程执行 `unsubscribe` 并退出；跨协程只传信号，不碰 socket。
3. 驱动差异要在构造期显式校验：phpredis + 协程 + 共享连接时给出明确错误或强制要求 `PubSubConnection`（专用连接）。
4. 至少：在 README/类注释中写明「PubSub 连接必须独占、不可跨协程复用、退订只能在订阅协程内发起」。

**验收标准**

- 单测/集成用例覆盖：协程内从回调退订、从另一协程退订、回调内执行普通 Redis 命令三种场景，均**不产生 `Swoole\Error`**，进程退出码为 0。
- 崩溃路径改为可预期的领域异常（如 `PubSubException`），并能被调用方 `try/catch`。
- 演示脚本 `tests/PubSub/*.php` 的退订调用取消注释后可正常运行。

**修复状态（2026-10-21）**：回调签名收敛为 `($channel, $payload)` / `($pattern, $channel, $payload)`，连接对象不再下发；退订由 `guardSocketContext()` 限定在 `loopCid` 上下文，越界只抛 `SubscriptionConflictException`（可 `catch`）；跨上下文停止走 `stop()`（`AbstractPubSub.php:107-128`、`:165-168`、`:440-455`）。建议 2 的「返回句柄」未采纳：句柄会被误当成跨协程可调用对象，所有权收口在抽象层内部状态机更稳。验收三项全部落到 `testUnsubscribeFromAnotherCoroutineIsRejectedWithoutFatal`、`testStopRequestedFromAnotherCoroutineEndsTheLoop`、`testInlineDispatchDeliversEveryMessageWithoutACoroutine`（断言回调参数只有 2 个 string）。

---

### PUB-02 `PredisPubSub::unsubscribe()` 新建 consumer，退订确认无人读取造成响应错位

**位置**

- `src/PubSub/PredisPubSub.php:118-132`
- `src/Redis/Predis.php:252-257`（`__call` 透传 `pubSubLoop`）
- `vendor/predis/predis/src/Client.php:600-603`（每次 `pubSubLoop()` 都 `new PubSubConsumer`）

**代码现状**

```php
public function unsubscribe(array $channels)
{
    $pubSubConsumer = $this->redis->pubSubLoop();   // 新建 consumer，不是活动的那个
    $pubSubConsumer->unsubscribe(...$channels);      // 只写命令，不读响应
}
```

`Consumer::unsubscribe()` 只做 `writeRequest()`（`Consumer.php:173-177`），响应必须由 `read()` 消费；新 consumer 立刻出栈销毁，`__destruct` 里 `stop(true)` 因 `valid()` 为 false 而直接返回（`Consumer.php:129-132`、`:228-247`、`:265-272`），**没人读那条 `unsubscribe` 确认**。

**根因**

1. 退订目标是「临时新建的 consumer」而不是活动 consumer，语义完全落空。
2. 写命令后不排空响应，RESP 流上留下一个未消费的数组，破坏「一问一答」配对。

**复现（非协程）** `/tmp/pubsub-probe/c_unsubscribe_desync.php`

```
unsubscribe() returned
call#0 set => ["unsubscribe","probe:desync",0] (array)   # SET 的响应被退订确认顶替
call#1 get => {} (Predis\Response\Status)                # GET 读到 SET 的 +OK
call#2 get => "v1" (string)                              # 再往后才拿到真正值
call#3 ping => "v1" (string)                             # PING 读到上一次 GET 的响应
```

从 `unsubscribe()` 起，该连接上**每条命令的响应都比预期滞后一位**，业务读到的是别人的返回值；Predis 不做响应类型校验，因此不会报错，只会静默给出错误数据。

**影响**

- PubSub 对象与业务共用同一连接时（模块允许，`publish()` 就是从同一对象发的），一次退订会让后续读写全部串位。
- `get` 返回 `Status` 对象、`ping` 返回字符串等异常类型会进一步在业务层触发 `TypeError` 或逻辑错误。

**修复建议**

1. `unsubscribe()`/`punsubscribe()` 必须操作 `getPubSubConsumer()` 返回的**活动 consumer**，并在没有活动循环时直接拒绝（抛 `PubSubException`：退订只能在订阅协程内发起）。
2. 若确实要支持「一次性退订」，写完命令后必须读取并丢弃对应确认，或调用 `Consumer::stop()` 由驱动统一收尾。
3. 明确 PubSub 连接独占原则：`PredisPubSub` 应在构造时校验/克隆出专用连接，不允许与业务命令共享。

**验收标准**

- 断言：`unsubscribe()` 之后的 `set/get/ping` 返回值与「未调用 `unsubscribe()`」的基线完全一致（无错位）。
- 无活动订阅时调用 `unsubscribe()` 抛出领域异常，而不是静默写入命令。
- 用例覆盖：回调内退订、外部退订、重复退订三种时序。

**修复状态（2026-10-21）**：`doUnsubscribe()` 复用 `activeConsumer()`，并新增 `drainConfirmations()` 在同一条连接上读完退订确认（期间到达的业务消息照常派发），全量退订后置空会话（`PredisPubSub.php:88-104`、`:118-139`）。无活动订阅时 `unsubscribe()` 抛 `InvalidSubscriptionException`（`AbstractPubSub.php:107-115`）。验收落到 `testCommandsAfterUnsubscribeStayAligned`：`set/exists/get/get/ping` 与基线一致。

---

### PUB-03 全量退订后缓存的 consumer 失效，重订阅静默不工作

**位置**

- `src/PubSub/PredisPubSub.php:77`（`getPubSubConsumer()`）、`:105-112`（缓存，永不失效）
- `vendor/predis/predis/src/Consumer/PubSub/Consumer.php:306-308`（确认计数为 0 时 `invalidate()`）、`:277-280`（`statusFlags = 0`）、`:151-155`（`subscribe()` 只置 `STATUS_SUBSCRIBED`）、`:265-272`（`valid()` 需同时含 `STATUS_VALID`）

**代码现状**

```php
protected function getPubSubConsumer()
{
    if (!$this->pubSubConsumer) {
        $this->pubSubConsumer = $this->redis->pubSubLoop();
    }
    return $this->pubSubConsumer;      // 一旦被 invalidate，永远不会被重建
}
```

**根因**

`Consumer::getValue()` 在收到「订阅数为 0」的确认时把 `statusFlags` 清零（`invalidate()`），此后 `valid()` 恒为 false。而 `subscribe()` 只补 `STATUS_SUBSCRIBED`，**不会恢复 `STATUS_VALID`**，于是 `foreach ($consumer as ...)` 一次都不进入循环体；`SUBSCRIBE` 命令却已经写进 socket，订阅在服务端真实存在，只是本地无人读取。

**复现（协程环境，派发闭包内联执行以隔离驱动行为）** `/tmp/pubsub-probe/d_resubscribe.php`

```
[pub] first sent
[cb1] probe:re => first
[unsub] calling unsubscribe
[unsub] done
[loop1] ended after 0.70s, count=1
[loop2] returned after 0.00s, count=1        # 第二次 subscribe 立即返回，回调零次执行
[pub] second sent
[main] final count=1
[main] ping => ["subscribe","probe:re",1]    # 第二次订阅的确认被当成 PING 响应
[main] ping2 => ["message","probe:re","second"]  # 真正的消息被当成 PING 响应读走
```

三个事实同时成立：重订阅零回调、连接仍停留在订阅态、后续命令继续错位（叠加 PUB-02）。

**影响**

- 生产上最常见的两种恢复路径全部失效：
  - 「退订 → 重新订阅」；
  - 「`while(true) { try { subscribe(...) } catch { retry } }`」这类外层重连循环（`tests/PubSub/Subscribe.php:32-44` 就是这个写法）——只要中间发生过全量退订，重连就变成静默空转，进程看起来活着但不再消费任何消息。
- 服务端仍认为该连接是订阅者（`receivers=1`），发布方计数正常，问题极难从发布侧发现。

**修复建议**

1. `getPubSubConsumer()` 增加有效性判断：`$this->pubSubConsumer === null || !$this->pubSubConsumer->valid()` 时重建 consumer。
2. `subscribe()` 进入前重置状态（把「一次订阅 = 一个 consumer 生命周期」显式建模），循环结束后释放引用。
3. 与 PUB-02 一并重构：PubSub 对象应持有「当前订阅会话」而不是裸缓存 consumer。

**验收标准**

- 用例：`subscribe → 收 1 条 → unsubscribe 全量 → 再 subscribe → 再收 1 条`，断言两次回调都执行且计数为 2。
- 用例：模拟驱动异常/超时后重建订阅，断言新连接与旧 consumer 不混用、无残留响应（`ping` 返回 `PONG`）。
- 用例：`subscribe()` 返回后对象可再次订阅成功（幂等重入）。

**修复状态（2026-10-21）**：consumer 生命周期与订阅会话绑定——`openLoop()` 的 `finally` 与全量退订都把 `$this->consumer` 置空，下次订阅重新 `pubSubLoop()`（`PredisPubSub.php:57-83`、`:106-113`）；`AbstractPubSub::finishSession()` 统一清理 `running/handler/loopCid/channels/patterns`（`:274-282`）。验收落到 `testResubscribeOnTheSameConnectionDeliversAgain`、`testFailureInOneSessionDoesNotBreakTheNext`。

---

## 5. P2 问题详情

### PUB-04 Predis 路径没有等价于 `OPT_READ_TIMEOUT = -1` 的读超时关闭

**位置**：`PredisPubSub.php:34-37`（构造只做 `parent::__construct()`）；对照 `RedisPubSub.php:31-32`

**复现**（`/tmp/pubsub-probe/b1_timeout_explicit.php`、`b2_timeout_default.php`，宿主机非协程，无发布者）

```
=== 显式 read_write_timeout=1
EXCEPTION Predis\TimeoutException: Operation has timed out (after 1.00s)
object(Predis\Response\Status)#37 { payload => "PONG" }     # 连接本身仍可用，是订阅循环终止了

=== 不配 read_write_timeout，仅 default_socket_timeout=1
EXCEPTION Predis\TimeoutException: Operation has timed out (after 1.00s)
```

**Swoole 协程下行为不同**（`/tmp/pubsub-probe/g_timeout_in_coroutine.php`）：`default_socket_timeout=1` 与 `read_write_timeout=1` 两种配置下订阅都**持续阻塞、不超时**（4 秒后被外部 kill）。即该问题只在非协程/未 hook 环境（CLI 消费者、单测、FPM）暴露。

**根因**：`Predis\Connection\Resource\StreamFactory.php:217-222` 仅在显式提供 `read_write_timeout` 时调用 `stream_set_timeout()`，否则读取继承 `default_socket_timeout`（宿主机为 60s）。`PredisPubSub` 对此不做任何处理，与 `RedisPubSub` 的显式 `-1` 语义不对称。

**次生影响**：`RedisRetryPolicy::isPredisConnectionException()` 把 `Predis\TimeoutException`（继承 `CommunicationException`，`vendor/predis/predis/src/TimeoutException.php:18`）判为连接异常，于是 `callWithRetry` 会 `closeNativeConnection()` + `reConnect()` 重建**包装器内的新 Client**，而 `$this->pubSubConsumer` 仍绑定旧 Client（实测 `attempt 1/2` 各超时一次，`/tmp/pubsub-probe/p_retry_after_timeout.php`），两侧从此指向不同连接。

**修复建议**：为 PubSub 专用连接强制 `read_write_timeout => -1`（或在构造时克隆参数并覆盖），并让 consumer 生命周期跟随重连后的 Client（见 PUB-03）；同时把「超时后连接/对象状态」写进 README。

**修复状态（2026-10-21）**：`PredisPubSub::__construct()` 调用 `disableReadTimeout()`，参数缺失或为正数时用 `applyParameters()` 重建 Client 并带上 `read_write_timeout=-1`（`PredisPubSub.php:36-44`、`:157-167`）；连接/会话状态说明已写入 `src/PubSub/README.md`「已知陷阱」。验收落到 `testIdleSubscriptionSurvivesShortReadTimeout`。

**验收标准**：非协程 + `default_socket_timeout=1` 环境下空闲 3 秒不抛异常；超时重连用例断言 consumer 与包装器指向同一连接。

---

### PUB-05 构造函数永久修改调用方连接的 `OPT_READ_TIMEOUT`

**位置**：`RedisPubSub.php:31-32`

**复现**（`/tmp/pubsub-probe/f_phpredis_options.php`）

```
before OPT_READ_TIMEOUT=0.0
after  OPT_READ_TIMEOUT=-1.0
unsubscribe outside subscribe returned      # 订阅态之外调用退订不报错（phpredis 侧无守卫）
bool(true)                                  # ping 仍正常
```

**根因**：对传入的 `RedisConnection` 直接 `setOption()`，没有「专用连接」约束、没有保存旧值、析构时也不恢复（`RedisConnection::__destruct()` 只清日志，`RedisConnection.php:390-393`）。

**影响**：`-1`（无限等待）会随连接回到连接池——池化场景下其它业务拿到这条连接后，一次网络黑洞就会永久阻塞整个协程/进程。这是 PubSub 模块唯一能把故障扩散到无关业务路径的地方。

**修复建议**：要求显式专用连接（构造时检测该连接是否已被池管理/是否已 `setOption` 过则拒绝），或记录旧值并在 `__destruct`/`stop()` 时恢复。

**修复状态（2026-10-21）**：改为「循环期间挂起、退出后恢复」：`openLoop()` 进入前 `suspendReadTimeout()` 记录原值并设 `-1`，`finally` 里 `restoreReadTimeout()` 原样写回（`RedisPubSub.php:51-64`、`:128-147`）。构造期不再触碰调用方连接。验收落到 `testPhpRedisReadTimeoutOptionIsRestoredAfterLoop`（循环内 `-1.0`、退出后 `2.5`）。

**验收标准**：断言构造 `RedisPubSub` 前后，外部持有的连接 `getOption(OPT_READ_TIMEOUT)` 未被改动，或模块明确拒绝共享连接。

---

### PUB-06 回调参数类型与文档在两个驱动间不一致

**位置**：`PredisPubSub.php:19-22`（`@var \Predis\Client`，实际是 `Swoolefy\Library\Redis\Predis` 包装器）、`:91`、`:96`（传 `$this->redis`）；`RedisPubSub.php:18-21`、`:63`（phpredis 回调第一个参数是原生 `\Redis`）

**复现**（`/tmp/pubsub-probe/n_callback_arg_type.php`）

```
=== Predis    callback redis arg class = Swoolefy\Library\Redis\Predis
=== phpredis  callback redis arg class = Redis
```

**影响**：写驱动无关回调的业务代码会得到不同类型（`$r->get()` 语义、返回值形态、`setOption` 是否存在都不同），错误只在运行时暴露。另外 `PredisPubSub` 的构造函数注释写成 `RedisPubSub constructor.`（`:30`），`@return void` 用在构造函数上，属于同一片段的可读性问题。

**修复建议**：回调不再传连接（见 PUB-01）；若必须传，统一为 `RedisConnection` 契约类型并修正 docblock。

**修复状态（2026-10-21）**：回调不再接收连接（见 PUB-01），两驱动共用同一段回调闭包；`PredisPubSub` 的构造注释与 `@return void` 一并清理。验收落到 `testInlineDispatchDeliversEveryMessageWithoutACoroutine`（2 参）与 `testPatternDispatchPassesPatternChannelPayload`（3 参）。

**验收标准**：同一份回调代码在 `--argv 1`（Predis）与默认（phpredis）两条演示路径下表现一致；`@param`/`@var` 与实际类型一致。

---

### PUB-07 失效的 `Predis\PubSub\Consumer` import

**位置**：`PredisPubSub.php:15`（`use Predis\PubSub\Consumer as PubSubConsumer;`）、`:25`、`:103`（`@var PubSubConsumer` / `@return PubSubConsumer|null`）

**复现**

```
php -r 'require "vendor/autoload.php";
var_dump(class_exists("Predis\\PubSub\\Consumer"), class_exists("Predis\\Consumer\\PubSub\\Consumer"));'
=> bool(false) / bool(true)
```

Predis 3.x 已把 consumer 移到 `Predis\Consumer\PubSub\Consumer`；本仓库锁定的正是 v3.7.0。因为代码从未 `new`/`instanceof` 该别名，运行期不报错，但类型标注全部指向不存在的类：静态分析（PHPStan/Psalm）报 `undefined class`，IDE 补全与跳转失效，`getPubSubConsumer()` 的返回类型注释也是假的。

**修复建议**：改为 `use Predis\Consumer\PubSub\Consumer as PubSubConsumer;`，并把 `getPubSubConsumer()` 声明为 `: Consumer`（重建逻辑见 PUB-03）。

**修复状态（2026-10-20）**：import 已更正为 `use Predis\Consumer\PubSub\Consumer as PubSubConsumer;`（`PredisPubSub.php:15`），`@var PubSubConsumer`（`:25`）与 `@return PubSubConsumer|null`（`:103`）随之指向真实类。实测反射取到 `getPubSubConsumer()` 返回对象为 `Predis\Consumer\PubSub\Consumer`（`instanceof` 为 true），`php -l` 通过。函数级返回类型声明属 PUB-03 的会话重构范围，本次未加。

**验收标准**：静态分析对 `src/PubSub` 零 undefined-class；`composer.json` 的 `predis/predis` 约束与 `Consumer` 命名空间一致（`~3.4` 区间内该命名空间恒成立）。

---

### PUB-08 订阅态下 `publish()` 必然失败，却仍作为同一对象的公开方法暴露

**位置**：`PredisPubSub.php:44-47`、`RedisPubSub.php:40-43`（两者都用订阅连接直接 `publish`）

**复现**（非协程，回调内发布）

```
=== Predis   （m_predis_publish_in_cb.php）
[cb] publish EXCEPTION Predis\Response\ServerException: ERR Can't execute 'publish':
     only (P|S)SUBSCRIBE / (P|S)UNSUBSCRIBE / PING / QUIT / RESET are allowed in this context

=== phpredis （l_phpredis_publish_in_cb.php）
[cb] pc
bool(false)          # 服务端同样拒绝，phpredis 只返回 false，不抛异常
```

**根因/影响**：Redis 服务端禁止订阅连接执行 `PUBLISH`。两驱动表现完全不同（一个异常、一个 `false`），而方法注释声明 `@return int`；`false` 会被业务当作「0 个订阅者」而不是失败，属于静默错误语义。仓库演示脚本也从不发布，说明这条路径从未被验证过。

**修复建议**：`publish()` 要求独立发布连接（构造参数或 `withPublisher(RedisConnection $plain)`）；当前连接处于订阅态时直接抛 `PubSubException`；phpredis 路径检查 `=== false` 并抛异常，与 Predis 对齐。

**修复状态（2026-10-21）**：`publish()` 在本实例已订阅时直接抛 `SubscriptionConflictException`；驱动返回 `false` 也抛 `PubSubException`；phpredis 侧把「连接已被其它订阅占用」的 `only (p|s)subscribe` 文本翻译成同一领域异常（`AbstractPubSub.php:134-158`、`:512-515`）。未引入 `withPublisher()`：多一个连接入口等于多一处所有权歧义，文档明确要求业务用另一个连接对象。验收落到 `testPublishWhileSubscribedThrowsConflictOnTheSameInstance`、`testPublishOnIdleConnectionReturnsReceiverCount`。

**验收标准**：订阅中调用 `publish()` 在两个驱动下都抛出同一领域异常；真实发布用例的返回值断言为 `int` 且 ≥1。

---

### PUB-09 空渠道/空模式无参数校验，两驱动行为分裂

**位置**：`PredisPubSub.php:79-82`、`RedisPubSub.php:60-73`、`:80-93`

**复现**（`/tmp/pubsub-probe/o_empty_channels.php`，3 秒后 kill）

```
=== predis   EXCEPTION Error: Cannot use object of type Predis\Response\Error as array
=== phpredis subscribe([]) returned normally        # 什么都没订阅，静默返回
```

**根因**：`subscribe([])` 经 `Consumer::subscribe(...$channels)` 展开成零参数命令，服务端返回 `-ERR wrong number of arguments`；`Consumer::getValue()` 把 `Error` 对象当数组下标访问（`Consumer.php:299`）而抛裸 `Error`。phpredis 则直接把空数组当成无操作。

**影响**：渠道名来自配置或数据库时，空集合是常见输入。Predis 抛的是不可读的内部 `Error`（非 `PubSubException`，调用方难以按契约捕获），phpredis 静默返回让消费进程「启动成功、什么都不干」。

**修复建议**：`AbstractPubSub` 增加 `assertNonEmpty(array $channels, string $api)`，空数组或非字符串元素直接抛 `InvalidArgumentException`/领域异常；`unsubscribe([])` 语义（全量退订）需在文档写明或同样拒绝。

**修复状态（2026-10-21）**：`normalizeNames()` 对 `subscribe/psubscribe/unsubscribe/punsubscribe/publish` 统一校验（空数组、非字符串、空串一律 `InvalidSubscriptionException`，消息含 API 名），且发生在触达 socket 之前（`AbstractPubSub.php:460-475`）；`unsubscribe([])` 选择「拒绝」而非全量退订，全量停止走 `stop()` 或显式传入已订阅名。验收落到 `testEmptyAndNonStringNamesAreRejectedBeforeTouchingTheSocket`、`testPublishRejectsEmptyChannel`、`testUnsubscribeWithoutActiveSessionIsRejected`。

**验收标准**：两驱动对 `subscribe([])`、`psubscribe([])` 抛出同类异常，异常消息包含 API 名；不出现 `Cannot use object of type ... as array`。

---

### PUB-10 每条消息一个协程：无背压、乱序、异常被吞

**位置**：`RedisPubSub.php:63-72`、`:82-92`；`PredisPubSub.php:90-98`

**现状**：`foreach` 每读到一条消息就 `goApp(...)` 派发，不等待、不限制并发、不保证顺序。swoolefy 的 `goApp()`（`function.php:192-210`）内部 `Coroutine::create` + `EventApp::run` + `catch (\Throwable) → BaseServer::catchException`，即：

- 回调异常只会进框架日志，订阅循环**完全无感知**，没有失败计数、没有重试、没有停止条件；
- `Coroutine::create` 返回 `false`（协程数达上限）时返回值被丢弃 → 消息静默丢弃；
- 突发流量下每条消息一个协程，处理顺序与完成顺序均不确定；
- Predis 原生支持的「回调返回 `false` 即停止循环」（`Client.php:636-640`）被这层派发屏蔽，业务无法停止订阅。

**影响**：适合「即发即忘」的通知场景，但对「顺序敏感 / 需要失败可观测」的下游（如 WebSocket 推送、状态机事件）不可靠，且故障只能靠框架日志发现。实测旁证（派发失败静默）：`/tmp/pubsub-probe/e_phpredis_goplay.php` 中 phpredis 的订阅回调内部抛出 `\Error`，扩展把异常吞掉，表现为「发布端报告 `receivers=1`、订阅端零输出且循环继续」；同一回调在定义可用派发闭包后（`e1_phpredis_with_goapp.php`）正常执行并 `exit=0`。即：**派发环节一旦失败，订阅侧没有任何可观测信号**，这正是本条要求「失败可见」的实测依据。

**修复建议**：把派发策略做成可配置（`inline` / `channel` / `coroutine+有限并发`），至少提供：并发上限与有界队列、消息顺序保证开关、回调失败回调（error handler）、回调返回 `false` 停止循环的能力。

**修复状态（2026-10-21）**：`dispatch` 选项取 `inline`（默认）/ `coroutine`，协程派发用 `Swoole\Coroutine\Channel` 作计数信号量实现 `max_concurrency` 上限与天然背压；`on_error` 存在时失败交回调、循环继续，不存在时记录首个异常并在循环退出后重抛给 `subscribe()` 调用方；`stats()` 暴露 `received/dispatched/completed/failed/in_flight`；回调返回 `false` 停止循环只对内联派发有效，协程派发用 `stop()`（`AbstractPubSub.php:295-330`、`:335-396`）。未做「顺序保证开关」：`inline` 即顺序执行、`coroutine` 即并发执行，两个模式本身已经表达了这层语义。验收落到 `testCoroutineDispatchIsBoundedByMaxConcurrency`、`testOnErrorHookMakesFailuresObservableAndKeepsDelivering`、`testCallbackFailureIsRethrownToTheSubscriber`。

**验收标准**：压测 1000 条消息时协程峰值受配置上限约束；顺序模式下回调执行顺序与到达顺序一致；回调抛异常时测试可断言失败计数/回调被调用。

---

### PUB-11 零自动化测试，演示脚本为死循环

**位置**：`tests/PubSub/Subscribe.php`、`tests/PubSub/Psubscribe.php`；`tests/phpunit.xml`（`<directory>tests</directory>`，默认只收集 `*Test.php`）

**现状**

- 两个脚本都是 `while (true) { try { $pubSub->subscribe(...); } catch (\Exception $e) { var_dump(...); } }`，用 `var_dump` 输出，靠 `argv[1]` 切换驱动；文件名不以 `Test.php` 结尾，**不会被 PHPUnit 收集**，CI 里 PubSub 覆盖率为 0。
- `catch (\Exception)` 抓不到 `\Error`：驱动侧抛出 `\Error` 时（如空渠道数组触发的 `Cannot use object of type Predis\Response\Error as array`，见 PUB-09）脚本直接崩溃而不是走重连分支，导致演示脚本无法验证「循环重连」这条被设计出来的路径。
- 脚本内的退订调用被注释（`Psubscribe.php:33`），即 PUB-01 的已知崩溃点，长期未被执行。
- `src/PubSub/` 没有 README，`docs/` 也没有 PubSub 技术方案（`grep PubSub docs/*.md` 无结果），模块的正确用法（连接独占、进程模型）无任何文字约定。

**修复建议**

1. 新增 `tests/PubSub/PubSubModuleTest.php`：用「真实本地 Redis + 短超时」覆盖 PUB-01~PUB-03 的验收场景（可 `markTestSkipped` 当 Redis 不可达，与 `Sms`/`Mail` 的容器用例风格一致）；协程用例标注仅在 Swoole 环境运行。
2. 演示脚本改名并挪出 `tests/`（例如 `examples/PubSub/subscribe.php`），或加 `if (PHP_SAPI !== 'cli') exit;` 与最大迭代次数，避免被误当成测试。
3. 补 `src/PubSub/README.md`：连接独占、订阅进程模型、两驱动差异、退订必须在订阅协程内发起、`publish` 需独立连接。

**验收标准**：`vendor/bin/phpunit -c tests/phpunit.xml --filter PubSub` 有真实用例执行；CI 在未安装 Swoole 的环境不产生假失败。

**修复状态（2026-10-21）**：新增 `tests/PubSub/PubSubModuleTest.php`（832 行、43 用例 / 133 断言，容器内连跑三次全绿）与 `src/PubSub/README.md`；两个 `while (true)` 演示脚本已 `git rm` 删除（不是改名迁出：其演示的旧回调 API 已在本次重构中移除，保留只会误导）。无 ext-redis / predis / Swoole 或 Redis 不可达时用例 `markTestSkipped`。注意实际执行命令需显式带目录：`tests/phpunit.xml` 的 `<directory>tests</directory>` 相对配置文件解析为 `tests/tests`，`--filter PubSub` 在该配置下收集不到用例。

---

## 6. 交叉影响与优先级判断

| 现象 | 触发链 | 说明 |
|---|---|---|
| 订阅进程活着但一条都不处理 | PUB-03（重订阅静默失效）→ PUB-04（空闲超时终止）→ PUB-10（派发/回调失败被吞） | 三种成因外部表现相同且均无日志；先补可观测性再修订阅会话生命周期 |
| 进程 `exit 255` 崩溃 | PUB-01（回调持有订阅连接 / 协程内退订） | swoolefy 协程默认形态即触发，属于最先该修 |
| 业务读到别人的返回值 | PUB-02 → PUB-03 | 同一连接被共享时污染范围最大，与 PUB-05 的「连接回池」叠加会跨请求扩散 |
| 空闲后订阅退出 | PUB-04（非协程）→ PUB-03（重订阅失效） | 二者叠加使「长时间空闲后的自动恢复」实际上不工作 |

**修复顺序建议**：PUB-01 → PUB-03 → PUB-02 →（PUB-05、PUB-04）→ PUB-06/PUB-07/PUB-08/PUB-09 → PUB-10 → PUB-11。PUB-01/PUB-02/PUB-03 都集中在「连接与订阅会话的所有权」，宜合并成一次重构而不是三次补丁。

---

## 7. 修复路线图（建议）

### 阶段一：连接与会话所有权（PUB-01 / PUB-02 / PUB-03 / PUB-05）

| 变更点 | 说明 |
|---|---|
| 引入「订阅会话」概念 | `subscribe()/psubscribe()` 返回 `SubscriptionHandle`（含 `stop()`），退订由句柄把信号交给订阅协程，绝不在其它协程触碰 socket |
| 回调签名去掉连接对象 | `function (string $channel, string $payload)`，`psubscribe` 为 `(string $pattern, string $channel, string $payload)`；需要 Redis 的业务自行使用独立连接 |
| `unsubscribe/punsubscribe` 复用活动 consumer | 无活动会话时抛 `PubSubException`；退订后校验并清理缓存（`$this->pubSubConsumer = null`） |
| 连接独占校验 | 构造时若传入连接已被复用（池/已订阅）直接 fail-fast；`OPT_READ_TIMEOUT` 不再改写外部连接 |

### 阶段二：派发与可观测（PUB-10）

| 变更点 | 说明 |
|---|---|
| 注入派发器 | `AbstractPubSub::setDispatcher(callable $dispatch)`；缺省时按 `isCoroutine` 选择内联或协程 |
| 派发结果可见 | 派发闭包返回值不再丢弃：`Coroutine::create` 返回 `false`（协程数达上限）时计入失败并触发错误回调；回调异常除框架日志外还上报给 PubSub 自身的错误处理器 |
| 并发上限 + 失败可见 | 有限并发队列、错误计数/回调、回调返回 `false` 可停止循环 |
| 移除构造期协程快照依赖 | 协程判定改为调用时判定，避免跨上下文复用产生两套语义 |

### 阶段三：驱动一致性与输入校验（PUB-04 / PUB-06 / PUB-07 / PUB-08 / PUB-09）

| 变更点 | 说明 |
|---|---|
| Predis 超时策略 | PubSub 专用连接强制 `read_write_timeout => -1`；超时后重建 consumer 与连接保持一体 |
| `publish()` 契约 | 独立发布连接；订阅态调用抛同一领域异常；phpredis 的 `false` 也抛异常 |
| 空参数校验 | `subscribe/psubscribe/unsubscribe/punsubscribe` 均拒绝空数组 |
| import / docblock | `Predis\Consumer\PubSub\Consumer` import 已修正（PUB-07）；仍需处理 `@var \Predis\Client` 与实际包装对象不符、构造函数 `@return void` 注释 |

### 阶段四：测试与文档（PUB-11）

新增 `tests/PubSub/PubSubModuleTest.php`（真实 Redis + Swoole 条件跳过）、`src/PubSub/README.md`，演示脚本迁出 `tests/`。

### 修复落地记录（2026-10-21，容器 `swoolefy-php85-v62` 实测）

最终实现与建议方案的差异：**没有引入 `SubscriptionHandle`**。订阅的所有权收口在 `AbstractPubSub` 内部状态机（`running` / `loopCid` / `stopRequested` + 本地订阅集合），公开 API 仍是 `subscribe/psubscribe/unsubscribe/punsubscribe/publish/stop`，但全部 `final`，驱动只能实现 `doPublish/openLoop/doUnsubscribe` 三个钩子。跨上下文停止走 `stop()`（只登记标志、不碰 socket），而不是返回句柄。

| 条目 | 落地方式 | 位置 |
|---|---|---|
| PUB-01 | 回调不再接收连接对象；`guardSocketContext()` 校验退订必须由 `loopCid` 所在上下文发起，否则抛 `SubscriptionConflictException`（可 `catch`，不再 `exit 255`）；跨上下文停止用 `stop()` | `AbstractPubSub.php:107-128`、`:165-168`、`:440-455` |
| PUB-02 | `doUnsubscribe()` 复用活动 consumer，并用 `drainConfirmations()` 把退订确认从同一条连接读干净，期间到达的业务消息照常派发 | `PredisPubSub.php:88-104`、`:118-139` |
| PUB-03 | consumer 生命周期 = 一次订阅会话：循环 `finally` 与全量退订后都置空，下次订阅重新 `pubSubLoop()` | `PredisPubSub.php:57-83`、`:106-113` |
| PUB-04 | 构造时若 `read_write_timeout` 缺失或为正数，`applyParameters()` 重建为 `-1`，空闲订阅不再被 `default_socket_timeout` 打断 | `PredisPubSub.php:36-44`、`:157-167` |
| PUB-05 | phpredis 的 `OPT_READ_TIMEOUT` 只在循环期间挂起（`suspendReadTimeout()`），退出后恢复原值，构造期不再改写外部连接 | `RedisPubSub.php:51-64`、`:128-147` |
| PUB-06 | 回调统一为 `(channel, payload)` / `(pattern, channel, payload)`，两驱动共用同一段回调代码，连接对象不下发 | `AbstractPubSub.php:218-240` |
| PUB-07 | import 更正为 `Predis\Consumer\PubSub\Consumer`（2026-10-20），并由 `testPredisConsumerClassIsTheOneImportedByTheDriver` 逐文件断言所有外部 import 可解析 | `PredisPubSub.php:15` |
| PUB-08 | `publish()` 在订阅态直接抛 `SubscriptionConflictException`；驱动侧 `false` 也抛 `PubSubException`；phpredis 侧把「连接已被其它订阅占用」的 `only (p|s)subscribe` 错误翻译成同一领域异常 | `AbstractPubSub.php:134-158`、`:512-515` |
| PUB-09 | `normalizeNames()` 拒绝空数组与非字符串渠道/模式，消息含 API 名，且在触达 socket 之前 | `AbstractPubSub.php:460-475` |
| PUB-10 | 派发策略显式化：`dispatch=inline`（默认）或 `dispatch=coroutine` + `max_concurrency`（`Swoole\Coroutine\Channel` 作计数信号量，构成背压）；`stats()` 计 `received/dispatched/completed/failed/in_flight`；无 `on_error` 时回调异常由循环协程重抛给 `subscribe()` 调用方 | `AbstractPubSub.php:295-330`、`:335-396` |
| PUB-11 | 删除两个 `while (true)` 演示脚本（`git rm`），新增 832 行 `tests/PubSub/PubSubModuleTest.php`（43 用例）与 `src/PubSub/README.md`（所有权契约、用法、6 条已知陷阱、异常表） | `tests/PubSub/`、`src/PubSub/README.md` |

新增文件：`src/PubSub/Exception/SubscriptionConflictException.php`、`src/PubSub/Exception/InvalidSubscriptionException.php`（`PubSubException` 保留为基类）。

---

## 8. 回归测试清单（修复后必测）

「实测」列为容器 `swoolefy-php85-v62` + 真实 Redis（`REDIS_HOST=host.docker.internal`）的执行结果，用例名省略公共前缀 `PubSubModuleTest::`。

| 编号 | 场景 | 断言要点 | 覆盖条目 | 实测用例 | 实测 |
|---|---|---|---|---|---|
| R-01 | 协程与非协程两种环境下的端到端收发（两驱动） | 两种环境回调都执行；派发失败（回调抛异常、`Coroutine::create` 返回 `false`）时订阅侧有可断言的信号，不出现「进程活着但零输出」 | PUB-10 | `testInlineDispatchDeliversEveryMessageWithoutACoroutine`、`testCallbackFailureIsRethrownToTheSubscriber`、`testOnErrorHookMakesFailuresObservableAndKeepsDelivering` | 通过 |
| R-02 | phpredis 协程：回调内调用退订 | 不产生 `Swoole\Error`，进程退出码 0，循环正常结束 | PUB-01 | `testUnsubscribeFromAnotherCoroutineIsRejectedWithoutFatal`（循环内退订走 `closeLoop()`）、`testStopRequestedFromAnotherCoroutineEndsTheLoop` | 通过 |
| R-03 | phpredis 协程：另一协程调用退订 | 同 R-02；退订在订阅协程内串行执行或有明确异常 | PUB-01 | `testUnsubscribeFromAnotherCoroutineIsRejectedWithoutFatal`（断言 `SubscriptionConflictException`，循环随后正常结束、连接仍可 `ping`） | 通过 |
| R-04 | 协程回调内使用传入连接执行 `get` | 不崩溃；若 API 已移除连接参数，则该用例改为断言签名 | PUB-01/PUB-06 | API 已移除连接参数，改为断言参数个数与类型：`testInlineDispatch...`（恒 2 个 string）、`testPatternDispatch...`（恒 3 个 string） | 通过 |
| R-05 | 非协程：`unsubscribe(['x'])` 后连续 `set/get/get/ping` | 各命令返回值与「未退订」基线完全一致（无错位） | PUB-02 | `testCommandsAfterUnsubscribeStayAligned`（`set/exists/get/get/ping`，两驱动） | 通过 |
| R-06 | `subscribe → 收到 1 条 → 全量 unsubscribe → 再 subscribe → 再收 1 条` | 两次回调都执行，`count=2`；重订阅不立即返回 | PUB-03 | `testResubscribeOnTheSameConnectionDeliversAgain`（两轮各 2 条） | 通过 |
| R-07 | 重订阅后 `ping` | 返回 `PONG`，而非 `["subscribe",...]`/`["message",...]` | PUB-03/PUB-02 | `testFailureInOneSessionDoesNotBreakTheNext`、`testCallbackFailureIsRethrownToTheSubscriber` 均在会话结束后断言 `ping() === 'PONG'` | 通过 |
| R-08 | 外层 `while(true)+try/catch` 重连循环（演示脚本同款） | 每次重连都能重新收到消息；`\Error` 也被处理（`catch (\Throwable)`） | PUB-03/PUB-04/PUB-11 | `testFailureInOneSessionDoesNotBreakTheNext`（第 1 轮抛 `RuntimeException`，第 2 轮照常收全） | 通过 |
| R-09 | 非协程 + `default_socket_timeout=1`，空闲 3 秒 | 不抛 `TimeoutException`（`read_write_timeout=-1` 生效） | PUB-04 | `testIdleSubscriptionSurvivesShortReadTimeout`（phpredis 用 `OPT_READ_TIMEOUT=1.0`，predis 用 `default_socket_timeout=1`，空闲 2.5 s 后仍收到消息） | 通过 |
| R-10 | 构造 `RedisPubSub` 前后检查外部连接 `getOption(OPT_READ_TIMEOUT)` | 值未被改动，或构造时拒绝共享连接 | PUB-05 | `testPhpRedisReadTimeoutOptionIsRestoredAfterLoop`（循环内 `-1.0`，退出后恢复 `2.5`） | 通过 |
| R-11 | 两驱动同一回调代码 | 回调第一个参数类型一致（或不存在该参数） | PUB-06 | 同 R-04：两驱动共用一个闭包体，`func_num_args()` 与参数序完全一致 | 通过 |
| R-12 | `class_exists('Predis\PubSub\Consumer')` 相关的 import 检查 | `src/PubSub` 静态分析零 undefined-class | PUB-07 | `testPredisConsumerClassIsTheOneImportedByTheDriver`（逐文件解析 `use` 并断言可加载） | 通过 |
| R-13 | 订阅中调用 `publish()` | 两驱动均抛同一领域异常；phpredis 不把 `false` 当 0 | PUB-08 | `testPublishWhileSubscribedThrowsConflictOnTheSameInstance`；空闲态计数由 `testPublishOnIdleConnectionReturnsReceiverCount` 断言 | 通过 |
| R-14 | `subscribe([])`、`psubscribe([])` | 两驱动抛同类参数异常，消息含 API 名；无 `Cannot use object ... as array` | PUB-09 | `testEmptyAndNonStringNamesAreRejectedBeforeTouchingTheSocket`（7 组数据）+ `testPublishRejectsEmptyChannel` | 通过 |
| R-15 | 1000 条消息突发 | 协程峰值 ≤ 配置上限；顺序模式执行顺序与到达顺序一致；回调异常计数可见 | PUB-10 | `testCoroutineDispatchIsBoundedByMaxConcurrency`（6 条突发、`max_concurrency=2`，断言峰值 ≤2 且 6 条全部按到达顺序处理完）。1000 条量级留给人工压测：用例只验语义不变量，条数不改变结论 | 通过 |
| R-16 | `vendor/bin/phpunit -c tests/phpunit.xml --filter PubSub` | 有用例执行且通过；无 Swoole/Redis 时按 skip 处理 | PUB-11 | 容器内实际命令需显式带上目录（`tests/phpunit.xml` 的 `<directory>tests</directory>` 相对配置文件解析为 `tests/tests`）：`REDIS_HOST=host.docker.internal ./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/PubSub` → `OK (43 tests, 133 assertions)`，连跑三次一致；宿主 macOS（PHP 8.4.14 / Swoole 6.1.7，直连 `127.0.0.1:6379`）同命令同样全绿（≈14.6 s） | 通过 |

补充用例（不属于报告清单，修复过程中新测出的风险面）：`testEachDriverRequiresItsOwnConnectionType`、`testCoroutineDispatchOutsideCoroutineIsRejected`、`testUnsubscribeWithoutActiveSessionIsRejected`、`testInvalidDispatchOptionsAreRejected`、`testInvalidMaxConcurrencyIsRejected`、`testSubscribeInsideCoroutineWithoutTcpHooksIsRejected`。


---

## 9. 附录

### 9.1 复现环境

| 项 | 值 |
|---|---|
| 宿主 | macOS (darwin 25.0.0, arm64)，PHP 8.4.14 CLI，Swoole 6.1.7，phpredis 已加载，`default_socket_timeout=60` |
| Redis | Docker 容器 `redis8.6.4`（`redis:8.6.4`）映射 `127.0.0.1:6379`，无密码 |
| 容器（Mail/Sms 阶段用过，本次 PubSub 复现主要走宿主机） | `swoolefy-php85-v62`，PHP 8.5.8 / Swoole 6.2.2，挂载 `/Users/macbook/Documents/wwwphp -> /home/wwwroot` |
| Predis | `vendor/predis/predis` **v3.7.0**（`composer.lock`） |
| goApp | swoolefy 框架全局函数（`/Users/macbook/Documents/wwwphp/swoolefy/src/Core/Func/function.php:192`）：`Coroutine::create` + `EventApp::run` + `catch (\Throwable) → BaseServer::catchException`。library 以「运行在 swoolefy 框架内」为前提；复现脚本里的 `goApp()` 替身仅用于隔离驱动行为 |

### 9.2 复现脚本清单（`/tmp/pubsub-probe/`）

| 脚本 | 目的 | 关键输出 |
|---|---|---|
| `publisher.php` | 延迟发布的独立进程 | `published to <ch>: <msg>, receivers=<n>` |
| `a_goplay.php` | Predis 无 `goApp` | `Error: Call to undefined function Swoolefy\Library\PubSub\goApp()` |
| `e_phpredis_goplay.php` | phpredis 协程无 `goApp` | 零输出、进程挂起，`receivers=1` |
| `e1_phpredis_with_goapp.php` | 定义 `goApp` 后 phpredis 回调正常 | `CALLBACK ... / count=1 / exit=0` |
| `h_phpredis_blocking.php` | 协程内回调退订 + 是否阻塞验证 | `Swoole\Error ... Socket#5`、`exit=255`；tick 0-3 正常执行 |
| `i_phpredis_unsub_external.php` | 另一协程退订 | `Swoole\Error ... Socket#5`、`exit=255` |
| `q_callback_uses_connection.php` | 回调内执行 `get`（两驱动） | 两侧均 `Swoole\Error`、`exit=255` |
| `k_phpredis_nocoroutine_cb.php` | 非协程回调内退订（对照） | `[sub] returned normally, count=1` |
| `j_predis_punsub_in_cb.php` | Predis 回调内 `punsubscribe`（只写不读，侥幸可用） | `[loop] returned after 0.49s count=1` |
| `c_unsubscribe_desync.php` | 退订后响应错位 | `set => ["unsubscribe",...]`、`get => Status`、`ping => "v1"` |
| `d_resubscribe.php` | 全量退订后重订阅 | `[loop2] returned after 0.00s`、`ping => ["subscribe",...]` |
| `p_retry_after_timeout.php` | 超时重试后的连接归属 | 两次均 `TimeoutException`，仅 `pubSubLoop` 日志 |
| `b1/b2_timeout_*.php` | 显式/默认读超时 | `Predis\TimeoutException: Operation has timed out (after 1.00s)` |
| `g_timeout_in_coroutine.php` | Swoole hook 下超时行为 | 1s/60s 配置均不超时（阻塞至被 kill） |
| `o_empty_channels.php` | 空渠道数组 | Predis `Error: Cannot use object of type Predis\Response\Error as array`；phpredis 静默返回 |
| `m/l_publish_in_cb.php` | 订阅态 `publish()` | Predis `ServerException`；phpredis `bool(false)` |
| `n_callback_arg_type.php` | 回调参数类型 | `Swoolefy\Library\Redis\Predis` vs `Redis` |

### 9.3 Predis 3.7.0 关键语义（对照 vendor 源码）

| 事实 | 位置 | 对本模块的意义 |
|---|---|---|
| `Predis\PubSub\Consumer` 不存在，实际为 `Predis\Consumer\PubSub\Consumer` | 实测 `class_exists` false/true | PUB-07 失效 import |
| `Client::pubSubLoop()` 每次都新建 consumer | `Client.php:600-603` | PUB-02 退订写成新对象 |
| 订阅数为 0 时 `getValue()` 调 `invalidate()` 把 `statusFlags` 清零 | `Consumer.php:306-308`、`:277-280` | PUB-03 缓存对象永久失效 |
| `valid()` 需同时满足 `STATUS_VALID` 与订阅位 | `Consumer.php:265-272` | 失效对象上再 `subscribe()` 也不进入 `foreach` |
| `unsubscribe()/punsubscribe()` 只 `writeRequest`，不读响应 | `Consumer.php:173-177`、`:204-207` | PUB-02 未消费确认；也解释 j 脚本「侥幸可用」 |
| `__destruct()` → `stop(true)`，但 `valid()` 为假时直接返回 | `Consumer.php:129-132`、`:228-247` | 新建的退订 consumer 不会替模块收尾 |
| 原生 `pubSubLoop($callable)` 支持回调返回 `false` 停止循环 | `Client.php:636-640` | 模块自建 `foreach` 后失去该能力（PUB-10） |
| `read_write_timeout` 未配置时读取继承 `default_socket_timeout` | `StreamFactory.php:217-222` | PUB-04 |
| `Predis\TimeoutException extends CommunicationException` | `TimeoutException.php:18` | 超时被 `RedisRetryPolicy` 判为连接异常，触发 reconnect（PUB-04 次生影响） |
| Redis 服务端订阅态只允许 SUB/PSUB/UNSUB/PUNSUB/PING/QUIT/RESET | 实测 `ServerException` 文本 | PUB-08 |

### 9.4 phpredis / Swoole 语义

- phpredis 的 `subscribe()` 在 Swoole `SWOOLE_HOOK_ALL` 下不会独占事件循环：订阅期间其它协程的 `System::sleep` 正常 tick（实测 h 脚本）。
- phpredis 在订阅回调里抛出的 `\Error` 会被扩展吞掉，不向调用栈传播、不打印（实测 e 脚本零输出）——这是「静默丢消息」的直接机制。
- Swoole 对同一 socket 的跨协程并发读写抛 `Swoole\Error: Socket#N has already been bound to another coroutine#M, reading of the same socket in coroutine#K at the same time is not allowed`；该错误以**未捕获致命错误**形式退出（`exit=255`），业务侧 `try/catch (\Throwable)` 无法兜住（实测 i 脚本已加 try/catch 仍然 fatal）。
- `Swoole\Coroutine::create()` 在协程外也可调用（Swoole 6.1 实测返回 cid=1，脚本结束时 `Swoole\Event::rshutdown()` 打印 deprecated），因此「非协程 + swoolefy `goApp`」不会崩，但会引入额外事件循环，语义上仍不适用于阻塞式订阅循环。

修复阶段（2026-10-21，容器 PHP 8.5.8 / Swoole 6.2.2 / phpredis + predis 3.7.0）新增实测：

- **phpredis 回调返回 `false` 不是「优雅停止」**：扩展直接断开连接并抛 `RedisException: read error on connection`（非协程下 `exit 255`）。因此驱动的 phpredis 回调恒返回 `true`，结束循环靠在循环上下文内显式 `unsubscribe()/punsubscribe()`（`RedisPubSub.php:85-121`）。
- **phpredis 会吞掉从回调里抛出的 `\Throwable`**：循环继续、零输出。修复方式是模块自己 `try/catch`，记录首个异常并在循环退出后由 `subscribe()` 重抛（`AbstractPubSub.php:335-362`），使失败对调用方可见。
- **`SWOOLE_HOOK_ALL` 下 phpredis 命令在协程外下发直接 `Swoole\Error: API must be called in the coroutine`**；`proc_open/proc_close` 同样受限。测试辅助调用因此只在必要处临时进入协程。
- **hook 只作用于「开启之后创建」的 socket，且订阅必须在协程内**：在协程外建连、再进协程跑阻塞订阅，会让阻塞读钉死整个事件循环——同进程发布协程永远得不到调度，表现为「进程活着但一条消息都收不到」的静默死锁（对照实测：连接在协程内创建 → 正常收发；在协程外创建 → 直接挂死被 kill）。`AbstractPubSub::guardCoroutineHooks()`（`:425-438`）据此在协程内缺少 `SWOOLE_HOOK_TCP` 时 fail-fast。swoolefy 侧 `SystemEnv::loadHookFlag()` 恒为 `SWOOLE_HOOK_ALL`，本守卫只拦截 library 被独立用在协程里的误用。
- **进程内跑过 Swoole 事件循环后，`proc_open` 的管道描述符会被 Swoole 的延迟关闭破坏**：后续调用报 `Unable to copy file descriptor N (for pipe) into file descriptor M: Bad file descriptor` 并返回 `false`，同时打印 `network::socket_free_defer(): close(N) failed, Bad file descriptor`。测试的延迟发布端因此改用 `pcntl_fork()`（子进程完成后 `SIGKILL` 自身 + 父进程 `SIGCHLD` 置 `SIG_IGN`，不产生僵尸），而不是 `proc_open`。
- **`\Swoole\Coroutine\run()` 的返回值是「事件循环是否跑完」，不回传闭包返回值**：需要结果必须用 `&$result` 取出，否则 `proc_open` 之类的资源会被丢成 `bool(true)`（本次用例的 `无法启动发布进程` 误报即源于此）。


### 9.5 已知限制 / 未纳入问题清单的观察

1. `AbstractPubSub::isCoroutine()` 在构造时快照（`AbstractPubSub.php:49-61`），跨上下文复用会静默切换回调执行模型；建议在阶段二重构派发模型时一并消除，不单独列条。
2. Redis Pub/Sub 本身是 at-most-once：进程重启期间消息不可补投。模块未提供 Stream 兜底或重放说明，属于能力边界而非缺陷；若业务需要可靠投递，应引导使用 `src/Queue`/Redis Stream。
3. `RedisConnection::log()` 会把渠道名与消息体写入 `lastLogs`（`RedisConnection.php:125-131`），若消息承载敏感数据需注意；模块默认不输出，风险低于 Mail/Sms 的日志面，故未列条。
4. 本次审查未修改任何源码；`src/PubSub` 的行为结论均来自上述定向复现。**2026-10-21 修复阶段**已在容器 `swoolefy-php85-v62` 内完成全部条目修复，并以上表用例回归（`tests/PubSub` 43 用例全绿、`tests/Redis` 25 用例无回归）；修复期新增的定向探针见 `/tmp/pubsub-probe/new_api.php`（新 API 双驱动 10 项 + 协程 9 项）、`t10.php`/`t11.php`/`t12.php`（hook 与建连顺序死锁对照）、`probe_fork.php`（fork 发布端替代 proc_open）。
