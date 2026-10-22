# Lock

基于 [malkusch/lock](https://github.com/php-lock/lock) **3.1.x** 的互斥锁封装，面向 swoolefy 的 Redis / MySQL / PostgreSQL 使用场景。

命名空间：`Swoolefy\Library\Lock`  
依赖：`malkusch/lock ~3.1.0`（PHP >= 8.4）

---

## 目录结构

```
Lock/
├── RedisMutex.php                  # phpredis / RedisCluster / Predis 分布式锁（推荐）
├── MySQLMutex.php                  # MySQL GET_LOCK
├── PostgreSQLMutex.php             # PostgreSQL advisory lock
├── CoroutineSynchronizedTrait.php  # 三个锁类共用的 synchronized 协程实现
└── README.md
```

3.1 的上游自旋等待已协程友好（`SWOOLE_HOOK_SLEEP` 让 `usleep()` 让出协程），但三个封装类仍通过 `CoroutineSynchronizedTrait` 覆盖了 `synchronized()`：保留旧版「获取锁后在新协程执行临界区、defer 释放、结果回传」的语义，避免临界区阻塞或父协程被取消时释放不及时。不再提供 2.x 的 `acquireLock()/releaseLock()` 手工配对接口。

---

## 类与选型

| 类 | 后端 | 说明 |
|----|------|------|
| `RedisMutex` | phpredis / RedisCluster / Predis | 多机分布式锁，生产首选 |
| `MySQLMutex` | MySQL `GET_LOCK` | 已有 MySQL、轻量互斥；**等待会阻塞 Worker** |
| `PostgreSQLMutex` | PG advisory lock | 需启用 `SWOOLE_HOOK_PDO_PGSQL` |

其余后端（`FlockMutex`、`MemcachedMutex`、`SemaphoreMutex`、`DistributedMutex`、`NullMutex`）直接使用 `Malkusch\Lock\Mutex\*`，无需封装。

---

## RedisMutex（推荐）

### 构造

```php
use Swoolefy\Library\Lock\RedisMutex;

// swoolefy 组件：包装器会自动解包为原生 \Redis / Predis\Client
$redis = Application::getApp()->get('redis')->getObject();
$lock = new RedisMutex($redis, 'order_lock', acquireTimeout: 5.0, leaseTimeout: 30.0);
```

| 参数 | 含义 | 约束 |
|------|------|------|
| `$client` | `\Redis` / `\RedisCluster` / `Predis\ClientInterface`，或 swoolefy `Redis`/`Predis` 包装器 | 必须是当前协程独占的连接 |
| `$name` | 锁名，实际 key 为 `php-malkusch-lock:{name}` | 非空 |
| `$acquireTimeout` | 获取锁的最大等待秒数 | `> 0`；`INF` 表示一直等待 |
| `$leaseTimeout` | 锁租期秒数 | 必须有限且 `> 0`（拒绝 `INF`，防止进程崩溃后永久死锁） |

### 标准临界区

```php
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Exception\LockReleaseException;

try {
    $result = $lock->synchronized(function () use ($service, $order) {
        return $service->deduct($order);
    });
} catch (LockAcquireTimeoutException $e) {
    // 未获得锁，临界区未执行
    return ['tag' => '系统繁忙，请稍后重试'];
} catch (LockReleaseException $e) {
    // 临界区已执行但释放失败：可从异常取回结果/原始异常做补偿
    $result = $e->getCodeResult();
    $cause = $e->getCodeException();
    throw $e;
}
```

### synchronized 的协程语义（三个封装类通用）

`RedisMutex` / `MySQLMutex` / `PostgreSQLMutex` 共用 `CoroutineSynchronizedTrait`，行为与上游实现的差异：

1. **获取锁在调用方协程**：`LockAcquireTimeoutException` 仍在业务调用处抛出，临界区不会执行。
2. **回调在子协程执行**：通过 `goApp()` 执行，子协程拥有独立的 `EventController` App 上下文（组件容器、连接池均独立）。
3. **释放后父协程才返回**：子协程在 `Coroutine::defer` 中释放锁，父协程等待释放完成才取回结果，不存在「返回时锁还没释放」的窗口；子协程被取消时 defer 仍会尝试释放。
4. **非协程环境回退上游实现**（CLI 脚本、未启用 Swoole 协程的单测）。
5. **子协程启动握手**：`goApp()` 的初始化异常会被框架吞掉并记日志，因此父协程最多等待 1 秒确认子协程已进入回调；失败则释放锁并抛 `MutexException`，不会永久阻塞。
6. **回调参数兼容旧版**：回调会收到当前 mutex 实例作为首个参数，零参闭包可忽略。

```php
$result = $lock->synchronized(function (RedisMutex $mutex) use ($order) {
    // 运行在子协程；需要新连接时使用 Application::getApp()->get('redis')
    return $order->deduct();
});
```

MySQL / PostgreSQL 的获取锁与释放锁都走同一个 PDO 连接，父子协程按「父协程挂起等待、子协程独占使用」的顺序交接；回调内需要新连接时同样从 `Application::getApp()` 获取。

### 业务异常

临界区内抛出的异常会原样透传到调用方协程，锁已在子协程 defer 中释放：

```php
try {
    $lock->synchronized(static function () {
        throw new DomainException('余额不足');
    });
} catch (DomainException $e) {
    // 锁已释放，可直接重试
}
```

### 未拿到锁就走（替代 2.x 的 `acquireLock()`）

3.1 的 `acquireTimeout = 0` 表示「一次都不尝试」，因此「短等待」要传一个很小的正值（`Loop` 最小退避 10 ms）：

```php
try {
    $lock = new RedisMutex($redis, 'order_lock', acquireTimeout: 0.05, leaseTimeout: 30.0);
    $lock->synchronized($code);
} catch (LockAcquireTimeoutException) {
    // 未拿到锁，业务未执行
}
```

### 条件临界区（双检锁）

```php
$result = $lock->check(static fn (): bool => $cache->has($key) === false)
    ->then(static fn () => $repository->loadAndCache($key));
// check 第一次不通过：不加锁、不执行 then，返回 false
// check 通过：加锁后再次 check，仍通过才执行 then
```

### 租期越界

临界区执行时间超过 `leaseTimeout` 时，释放阶段抛 `ExecutionOutsideLockException`（`LockReleaseException` 子类），此时锁已按 token 删除，但**业务已在锁外执行过**：

```php
try {
    $lock->synchronized(static fn () => $slowJob());
} catch (ExecutionOutsideLockException $e) {
    // $e->getCodeResult() / getCodeException() 可还原业务结果
    // 该场景说明副作用可能越界，需按幂等/补偿处理
}
```

### 锁 key 与运维

- 锁 key：`php-malkusch-lock:{name}`，值：64 位随机 token，TTL = `leaseTimeout`。
- 排查死锁：`SCAN MATCH php-malkusch-lock:*` 查看 key 与 TTL。
- 2.x 的 `lock_{name}` 键与 3.x **不互斥**；滚动升级期间需全量切换或做业务幂等兜底。

---

## MySQLMutex

```php
use Swoolefy\Library\Lock\MySQLMutex;

$lock = new MySQLMutex(App::getDb(), 'order_lock', acquireTimeout: 1.0);
$lock->synchronized($code);
```

- 上游会为锁名加 `php-malkusch-lock:` 前缀，`name` 最长 **46** 字符，超长抛 `InvalidArgumentException`。
- `acquireTimeout = 0` 表示只尝试一次（`GET_LOCK(name, 0)`），不是「永不尝试」。
- `synchronized()` 与 RedisMutex 使用同一套协程语义：回调在 `goApp()` 子协程执行，`RELEASE_LOCK` 在子协程 defer 中执行，父协程等到释放完成才返回。
- **Swoole 未 hook PDO_MYSQL**，`GET_LOCK` 等待期间当前 Worker 上所有协程都会停摆；请求路径请使用短超时或改用 `RedisMutex`。回调内的 PDO 查询同样不受 hook，慢查询会阻塞 Worker。
- `getPdo()` 为空时构造失败，需先建立连接。

## PostgreSQLMutex

```php
use Swoolefy\Library\Lock\PostgreSQLMutex;

$lock = new PostgreSQLMutex(App::getPgSql(), 'order_lock', acquireTimeout: 5.0);
$lock->synchronized($code);
```

- 有限超时使用 `pg_try_advisory_lock` + 自旋重试，前提是 Worker 启用 `SWOOLE_HOOK_PDO_PGSQL`。
- 本封装拒绝 `INF`（上游会走 `pg_advisory_lock` 无限阻塞）；确实需要无限等待时直接使用 `Malkusch\Lock\Mutex\PostgreSQLMutex`。
- `synchronized()` 与 RedisMutex 使用同一套协程语义：回调在 `goApp()` 子协程执行，`pg_advisory_unlock` 在子协程 defer 中用同一 PDO 连接释放，父协程等到释放完成才返回。

---

## Redlock 多节点

3.1 用显式 `DistributedMutex` 替代了 2.x 的「客户端数组」构造：

```php
use Malkusch\Lock\Mutex\DistributedMutex;
use Swoolefy\Library\Lock\RedisMutex;

$mutex = new DistributedMutex([
    new RedisMutex($redisA, 'order_lock', 3.0, 30.0),
    new RedisMutex($redisB, 'order_lock', 3.0, 30.0),
    new RedisMutex($redisC, 'order_lock', 3.0, 30.0),
], 3.0, 30.0);
```

---

## 异常契约

| 异常 | 场景 | 临界区是否执行 |
|------|------|----------------|
| `LockAcquireTimeoutException` | 等待锁超时 | 否 |
| `LockAcquireException` | 锁后端错误（连接/脚本等） | 否 |
| `LockReleaseException` | 释放失败 | **是**（`getCodeResult()`/`getCodeException()` 可取回结果） |
| `ExecutionOutsideLockException` | 临界区超出 `leaseTimeout` | **是**，且部分副作用可能发生在锁外 |

业务侧至少要分别处理「未拿到锁」与「释放失败」。

---

## 协程约束（重要）

1. **一个 mutex 实例只能被一个业务协程独占使用**。phpredis 连接在 Swoole 下与协程绑定，跨业务协程并发共享会触发 `Socket#N has already been bound to another coroutine` 致命错误；MySQL/PostgreSQL 的 PDO 连接同样不能并发放到两个业务协程里。
2. `synchronized()` 内部会把连接（Redis 或 PDO）从父协程交接给执行回调的子协程（父协程挂起等待、子协程独占使用），这是模块内的受控交接，不需要额外处理。
3. 每个协程使用自己从组件容器 / 连接池租借的客户端构造锁。swoolefy 中直接 `get('redis-order-lock')` 即可：容器按协程缓存单例，同一协程顺序复用安全；不要缓存到 Worker 级静态变量，也不要把实例传给其他协程。
4. `synchronized()` 的回调耗时应显著小于 `leaseTimeout`，否则释放阶段抛 `ExecutionOutsideLockException`：业务结果/异常仍可通过 `getCodeResult()` / `getCodeException()` 取回，但副作用可能已越界。
5. 子协程内 `Application::getApp()` 是独立的 `EventController`，需要连接时重新 `get()`，不要使用父协程闭包里捕获的连接/组件 DTO。
6. **连接池容量决定并发等待上限**：`lock()` 自旋等待期间持续占用一个 Redis 连接，池耗尽时请求会在进入 `synchronized()` 之前被框架拒绝（Test 默认 5 + 10 降级/Worker，100 并发即触发 503）。生产按峰值等待者数量配置 `max_pool_num` / `fallback.max_concurrent`。

---

## 从 2.x 迁移

| 2.x | 3.1 |
|-----|-----|
| `PHPRedisMutex([$redis], $name, $timeout)` | `RedisMutex($redis, $name, $acquireTimeout, $leaseTimeout)` |
| `PredisMutex([$redis], $name, $timeout)` | `RedisMutex($redis, $name, $acquireTimeout, $leaseTimeout)` |
| `PgAdvisoryLockMutex($pgsql, $name)` | `PostgreSQLMutex($pgsql, $name, $acquireTimeout)` |
| `MySQLMutex($mysql, $name, 0)` | `MySQLMutex($mysql, $name, 3.0)`（参数改为 float 秒） |
| `acquireLock()` / `releaseLock()` | `synchronized()`、`check()->then()`、短超时 `synchronized()` |
| `malkusch\lock\exception\TimeoutException` | `Malkusch\Lock\Exception\LockAcquireTimeoutException` |
| key `lock_{name}` | key `php-malkusch-lock:{name}` |
| 单个 `$timeout` 同时控制等待和租期 | 拆分为 `acquireTimeout` / `leaseTimeout`，租期必须显式有限 |
| `SynchronizeTrait::synchronized()`（goApp 子协程 + defer 释放） | `CoroutineSynchronizedTrait`（三个锁类共用，语义相同，见上） |

已移除（上游 3.1 不存在对应类）：`CASMutex`、`TransactionalMutex`、`FlockMutex`、`MemcachedMutex`；旧 `SynchronizeTrait` 文件已删除，其协程语义由 `CoroutineSynchronizedTrait` 承接。需要文件锁/Memcached 锁请直接使用 `Malkusch\Lock\Mutex\FlockMutex` / `MemcachedMutex`。

---

## 测试

```bash
# 容器内（Redis 通过 REDIS_HOST / REDIS_PORT 指定）
REDIS_HOST=192.168.60.83 vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Lock
```

MySQL/PostgreSQL 用例按以下环境变量连接，不可达时自动 skip：

- MySQL：`DB_HOST_NAME`、`DB_HOST_PORT`、`DB_HOST_DATABASE`、`DB_USER_NAME`、`DB_PASSWORD`
- PostgreSQL：`POSTGRES_HOST`、`POSTGRES_PORT`、`POSTGRES_DATABASE`、`POSTGRES_USER`、`POSTGRES_PASSWORD`

覆盖：构造校验、key 命名与 TTL、协程互斥、获取超时、租期越界、业务异常释放、phpredis/predis 双驱动、swoolefy 包装器解包、跨客户端互斥、子协程执行与释放时序、非协程回退；MySQL/PG 覆盖子协程执行、释放后可重入、业务异常透传、获取超时与非协程回退。
