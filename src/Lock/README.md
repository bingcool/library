# Lock

基于 [malkusch/lock](https://github.com/php-lock/lock) 的互斥锁封装，对接 swoolefy 常用存储（Redis / MySQL / PostgreSQL 等），并为 Redis 锁补充了 **协程友好的 `synchronized`**。

命名空间：`Swoolefy\Library\Lock`  
依赖：`malkusch/lock ^2.2`

---

## 目录结构

```
Lock/
├── PHPRedisMutex.php        # phpredis 分布式锁（推荐）
├── PredisMutex.php          # Predis 分布式锁
├── SynchronizeTrait.php     # acquire/release + 协程 synchronized
├── MySQLMutex.php           # MySQL GET_LOCK
├── PgAdvisoryLockMutex.php  # PostgreSQL 咨询锁
├── TransactionalMutex.php   # PDO 事务型临界区（可重放）
├── FlockMutex.php           # 本地文件锁
├── MemcachedMutex.php       # Memcached 锁
├── CASMutex.php             # CAS 自旋锁
└── README.md
```

---

## 选型对照

| 类 | 后端 | 适用场景 |
|----|------|----------|
| `PHPRedisMutex` | phpredis | 多机分布式锁（推荐） |
| `PredisMutex` | Predis | 同上，Predis 客户端 |
| `MySQLMutex` | MySQL `GET_LOCK` | 已有 MySQL、轻量分布式 |
| `PgAdvisoryLockMutex` | PG advisory lock | PostgreSQL 环境 |
| `TransactionalMutex` | PDO 事务 | DB 内临界区，失败可重放 |
| `FlockMutex` | 本地文件 | 单机脚本 / 单机互斥 |
| `MemcachedMutex` | Memcached | 已有 Memcached 集群 |
| `CASMutex` | 内存 CAS | 进程内自旋（非分布式） |

---

## Redis 分布式锁（推荐）

### 构造

```php
use Swoolefy\Library\Lock\PHPRedisMutex;
use Swoolefy\Library\Lock\PredisMutex;

$redis = Application::getApp()->get('redis');
$lock = new PHPRedisMutex([$redis], 'order_lock', 5);
// 参数：Redis 实例数组、锁名、timeout 秒
// timeout：获取锁的最大等待时间；超时抛 malkusch\lock\exception\TimeoutException
// Redis Key 实际为：lock_{name}（前缀 lock_）

$predis = Application::getApp()->get('predis');
$lock2 = new PredisMutex([$predis], 'order_lock-1', 5);
```

### 方式一：`synchronized`（推荐，协程安全）

获取锁 → 在新协程执行回调 → defer 释放锁 → 把结果返回当前协程。

```php
use malkusch\lock\exception\TimeoutException;

try {
    $result = $lock->synchronized(function () {
        // 临界区业务
        return ['ok' => true];
    });
} catch (TimeoutException $e) {
    // 等待超时：未拿到锁
    return ['tag' => '锁等待超时'];
}
```

注意：

- 业务耗时若接近/超过 `$timeout`，锁可能已过期，其它请求可能进入；请合理设置 timeout，并保证临界区尽量短。
- 回调抛出的异常会通过 Channel 传回并重新抛出。

### 方式二：手动 `acquireLock` / `releaseLock`

非阻塞尝试获取（具体重试策略由父类 `RedisMutex` 决定）：

```php
if ($lock->acquireLock()) {
    try {
        // 临界区
    } finally {
        $lock->releaseLock(); // 失败抛 LockReleaseException
    }
} else {
    // 未拿到锁
}
```

### swoolefy 组件

```php
// Config/component/common.php
'redis-order-lock' => function () {
    $redis = Application::getApp()->get('redis');
    return new PHPRedisMutex([$redis], 'order_lock', 5);
},
```

```php
App::getRedisLock()->synchronized(fn () => /* … */);
```

试调：

- `GET /api/lock-test1` — `synchronized`
- `GET /api/lock-test2` — `acquireLock` / `releaseLock`

---

## MySQL / PostgreSQL

```php
use Swoolefy\Library\Lock\MySQLMutex;
use Swoolefy\Library\Lock\PgAdvisoryLockMutex;

$db = Application::getApp()->get('db');   // Mysql
$lock = new MySQLMutex($db, 'order_row_1', 3);
$lock->synchronized(function () {
    // …
});

$pg = Application::getApp()->get('pg');   // Pgsql
$pgLock = new PgAdvisoryLockMutex($pg, 'order_row_1');
$pgLock->synchronized(function () {
    // …
});
```

底层使用库内 PDO；`MySQLMutex` 的 `$timeout` 对应 `GET_LOCK` 等待秒数（`0` 表示不等待）。

---

## TransactionalMutex

在 PDO 事务中执行临界区：遇 `PDOException`（含嵌套 previous）会回滚并**重放**；其它异常回滚后直接抛出。

```php
use Swoolefy\Library\Lock\TransactionalMutex;

$mutex = new TransactionalMutex($db, 3); // PDOConnection, timeout
$result = $mutex->synchronized(function () {
    // 仅应包含可重入的 SQL 副作用；隔离级别由业务自行设置
    return true;
});
```

要求：

- `PDO::ATTR_ERRMODE` 必须为 `ERRMODE_EXCEPTION`
- 非 MySQL 驱动建议关闭 `ATTR_AUTOCOMMIT`

---

## 其它锁

```php
// 文件锁（单机）
$flock = new \Swoolefy\Library\Lock\FlockMutex(fopen('/tmp/app.lock', 'c'));

// Memcached
$mem = new \Swoolefy\Library\Lock\MemcachedMutex($memcached, 'name', 3);

// CAS（进程内）
$cas = new \Swoolefy\Library\Lock\CASMutex(3);
```

更多 API 行为见 [malkusch/lock 文档](https://github.com/php-lock/lock)。

---

## 异常

| 异常 | 含义 |
|------|------|
| `TimeoutException` | 在 timeout 内未获取到锁 |
| `LockAcquireException` | 加锁失败（如 Redis 异常） |
| `LockReleaseException` | 解锁失败 |

业务侧应对 `TimeoutException` 做友好返回，避免未捕获导致 500。

---

## 注意点

1. **锁名唯一**：按业务维度命名（如 `order_{id}`），避免无关请求互斥。  
2. **timeout 与业务时长**：`synchronized` 等待与锁 TTL 相关；临界区勿长时间 `sleep`。  
3. **协程**：Redis 锁的 `synchronized` 通过 `goApp` + Channel 归还结果；创建协程失败会主动 `releaseLock` 防泄漏。  
4. **多 Redis**：构造函数接收 `array $redisAPIs`，可传入多个实例（父类 Redlock 风格语义以 malkusch 实现为准）。  
5. **与 DB 事务**：分布式锁 ≠ 数据库事务；需要原子写库时，锁内仍应使用 DB 事务，或改用 `TransactionalMutex`。

---

## 快速对照

```php
$lock = new PHPRedisMutex([$redis], 'order_lock', 5);

// 推荐
$result = $lock->synchronized(fn () => doWork());

// 或手动
if ($lock->acquireLock()) {
    try { doWork(); } finally { $lock->releaseLock(); }
}
```
