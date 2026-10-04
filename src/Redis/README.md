# Redis

PHPRedis、Predis、RedisCluster 的统一封装。三套驱动共用同一套断线处理：先恢复连接，再按命令白名单决定是否重放。

命名空间：`Swoolefy\Library\Redis`  
依赖：`ext-redis`（`Redis` / `RedisCluster`）、`predis/predis`（`Predis`）

---

## 目录结构

```
Redis/
├── Redis.php                 # ext-redis 单机
├── Predis.php                # Predis\Client
├── RedisCluster.php          # ext-redis Cluster
├── RedisConnection.php       # 重连、会话恢复、日志
├── RedisRetryPolicy.php      # 命令白名单
└── README.md
```

业务命令都走 `__call`，方法名与原生客户端一致，例如 `$redis->get()`、`$redis->hGet()`。

---

## 选型

| 类 | 何时使用 | 连接方式 |
|----|----------|----------|
| `Redis` | 已安装 `ext-redis` 的单机或持久连接 | `connect()` / `pconnect()`，然后 `auth()` |
| `Predis` | 不能装扩展，或要沿用 Predis 参数 | 构造函数传入 parameters / options |
| `RedisCluster` | Redis Cluster | 构造函数传入 name、seeds、auth |

三套驱动的重试语义相同。不要对同一条连接绕过封装、直接用原生客户端发写命令，否则不会经过白名单。

---

## 用法

### PHPRedis

```php
use Swoolefy\Library\Redis\Redis;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379, 2.0);
$redis->auth('secret');
$redis->select(2);

$value = $redis->get('user:1');
```

`connect()` 之后的密码必须走 `auth()`。重连只会恢复这里记下的密码。`select()` 的库号也会在重连后重新执行。

`pconnect()` 为持久连接，对象析构时不主动 `close()`。

### Predis

```php
use Swoolefy\Library\Redis\Predis;

$redis = new Predis([
    'scheme' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 6379,
    'database' => 2,
    'password' => 'secret',
]);

$value = $redis->get('user:1');
```

`setConfig()` 只在尚未初始化时生效。之后若业务又调用了 `select()` / `auth()`，断线重建会按这两次调用恢复，而不是只靠最初的 parameters。

### Cluster

```php
use Swoolefy\Library\Redis\RedisCluster;

$redis = new RedisCluster(
    'app',
    ['127.0.0.1:7000', '127.0.0.1:7001'],
    1.5,
    1.5,
    false,
    'secret'
);
```

Cluster 没有 `SELECT`。重连按构造参数重建客户端，不恢复 db index。

---

## 断线与重试

原则是 **Reconnect ≠ Retry**。

```text
命令执行
  ├── 成功 → 返回
  ├── 业务错误（WRONGTYPE、NOSCRIPT、参数错误）→ 直接抛出，不重连
  └── 连接异常
        ├── 关闭死连接并重建（恢复 AUTH；单机再恢复 SELECT）
        ├── 命令在白名单内，且不在 MULTI / PIPELINE / WATCH 中 → 最多再执行 1 次
        └── 写命令、未知命令、事务中 → 抛出第一次的连接异常
```

连接异常不等于服务端没执行成功。`INCR`、`LPUSH`、`SET`、`EVAL` 若自动再执行一次，会造成重复副作用，因此默认不重放。`GET`、`HGET`、`SMEMBERS` 这类只读命令可以重放。

| 行为 | 说明 |
|------|------|
| 默认可重放 | 明确只读、且没有 `STORE` 之类写选项的命令。`GEORADIUS` 可写，不在名单里；`GEORADIUS_RO` 在 |
| 默认不重放 | `SET`、自增、List/Hash/Set/ZSet 写、`EVAL` / `EVALSHA`、`SCAN` 家族、`XREAD` / `XREADGROUP`、未知命令 |
| `rawCommand` / `executeRaw` | 按第一个参数识别真实命令。`rawCommand('INCR', $key)` 不重放，`rawCommand('GET', $key)` 可以 |
| MULTI / PIPELINE / WATCH | 断线后新连接不在原事务里，任何命令都不重放 |
| 次数 | 额外重放默认 1 次，上限 3 次。第二次仍失败就抛出，不再第三次 |
| 等待 | 仅连接异常路径在重连前等待，默认 `0.5` 秒。业务错误不会 sleep |
| 重连失败 | 抛出 `RuntimeException`，`previous` 保留第一次连接异常 |

`WRONGTYPE` 在 PHPRedis 里也是 `RedisException`，在 Predis 里是 `ServerException`。这两种都不会重连。Predis 只把 `ConnectionException`、`CommunicationException` 视为断线。

完整判定见 `RedisRetryPolicy` 与 [Redis / PDO Retry 方案](../../docs/library-6.x-Redis-PDO-Retry技术方案.md)。

### 调整白名单

```php
$redis->setRetryOptions([
    'enabled' => true,
    'max_times' => 1,
    'delay' => 0.5,
    // 'commands' => ['GET'],          // 非空时整表替换内置白名单
    'extra_commands' => ['SET'],       // 在当前白名单上追加
]);
```

`enabled = false` 时仍然重连，但任何命令都不重放。不要只抄几个命令放进 `commands`，那会丢掉其余只读命令；需要加命令时用 `extra_commands`。

---

## 拿到底层客户端

| 方法 | 返回 |
|------|------|
| `getRedisInstance()` | 原生 `\Redis` |
| `getPredisInstance()` | `Predis\Client` |
| `getRedisClusterInstance()` | 原生 `\RedisCluster` |

通过这些实例直接发命令不会经过本目录的重连和白名单。

---

## 注意点

1. `setOption()` 设置的 prefix、serializer 在重连后不会自动恢复。依赖这些选项时，断线后要重新设置。
2. 重试日志只记命令名、次数和原因，不记参数和 value。成功路径的调试日志仍可能包含参数，可用 `setLimitLogNum()` 限制条数，用 `getLastLogs()` 查看。
3. 协程里重连前的等待使用 `Swoole\Coroutine\System::sleep`，不会卡住整个 Worker。非协程环境退回 `usleep`。
4. `Redis::isConnect()` 直接对原生连接执行 `PING`，不走重试包装。
