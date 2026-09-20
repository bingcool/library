# Library 6.x Redis / PDO 自动重连与安全 Retry 技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 方案范围 | PHPRedis、Predis、RedisCluster、PDO（Mysql / Pgsql / Oracle / Sqlite） |
| 优先级 | P0 |
| 目标 | 消除连接异常后的危险重复执行，同时保留明确安全操作的自动恢复能力 |
| 核心原则 | Reconnect 与 Retry 解耦 |
| 设计目标 | 不引入复杂幂等系统，不增加业务层功能，不引入 SQL AST Parser |

对照文件：

| 组件 | 文件 | 当前问题 |
|---|---|---|
| PHPRedis | `src/Redis/Redis.php` | `__call` 捕获任意异常后 reconnect 并盲 replay 1 次 |
| Predis | `src/Redis/Predis.php` | 同上，且会把 Redis 业务错误（如 WRONGTYPE）当成断线 |
| RedisCluster | `src/Redis/RedisCluster.php` | 与 PHPRedis 相同盲 replay，原方案未覆盖 |
| PDO | `src/Db/PDOConnection.php` | 非事务下断线最多盲 replay 4 次，不区分 SQL 类型 |

---

# 2. 现状代码分析

原方案对问题的判断方向正确，但对当前实现的描述不完整。真实风险比「只在连接异常后 replay」更大。

## 2.1 Redis / Predis / RedisCluster：任意异常都会 replay

`Redis::__call` 当前逻辑：

```text
try
    native command
catch RedisException|Exception
    sleep(0.5)
    close + reConnect()
    再次执行原 command        ← 无命令白名单
```

对应代码：

- `src/Redis/Redis.php` `__call`
- `src/Redis/Predis.php` `__call`
- `src/Redis/RedisCluster.php` `__call`

三个问题：

1. **不是只处理连接异常。** PHPRedis 的 `WRONGTYPE`、`NOSCRIPT`、错误参数等也是 `RedisException`，当前都会 reconnect + replay。
2. **Predis 捕获了全部 `\Exception`。** `\Predis\Response\ServerException`（Redis 返回的业务错误）也会走重连重放。只有 `\Predis\Connection\ConnectionException` 才应视为断线。
3. **reconnect 后无条件再执行原命令。** `INCR` / `LPUSH` / `EVAL` 都会被自动执行第二次。

此外：

- `reConnect()` 只恢复 `auth`，**不恢复 `SELECT db`**。用户切过 DB 后，断线重连会落到 DB 0 再 replay，读命令也会读错库。
- 每次异常固定 `sleep(0.5)`，即使是业务错误也会被拖慢。
- `RedisCluster` 与单机 Redis 是同一类 bug，必须纳入本方案，不能只改 PHPRedis / Predis。

## 2.2 PDO：非事务 SQL 最多盲 replay 4 次

`PDOConnection::PDOStatementHandle()` 当前逻辑：

```text
prepare + bind + execute
catch PDOException
    if transTimes <= 0
       and reConnectTimes < 4
       and (isBreak(e) or SQLSTATE 2006/2013)
        close()
        再次执行同一条 SQL          ← 不区分 SELECT / INSERT / UPDATE
```

已有保护：

- **事务中不 replay**（`transTimes <= 0` 才重试）。这条必须保留为硬规则。
- `beginTransaction()` 失败时只重试「开启事务」，没有业务 SQL replay，可保留。
- `connect()` 失败时只重试建连，没有业务 SQL replay，可保留。

现存问题：

1. 非事务下 `INSERT` / `UPDATE` / `DELETE` 都会被 replay，最多 4 次。
2. `isBreak()` 的匹配串包含 `Resource deadlock avoided`。死锁不是「结果不确定的断线」：autocommit 下语句已被回滚，语义与 connection lost 不同，不应走同一条 reconnect + 盲 replay 路径。
3. 事务中断线时直接 `throw`，**不会 `close()`**。连接已死但 `transTimes` 仍大于 0，后续请求可能继续打在坏连接上。

## 2.3 原方案需要修正的点

| 原方案 | 结论 |
|---|---|
| Reconnect ≠ Retry，白名单才 replay | 正确，作为总原则保留 |
| 只覆盖 PHPRedis / Predis | **不完整**，必须含 RedisCluster |
| Redis 配置示例只有 5 个命令 | **与默认白名单矛盾**，需区分内置默认值与用户增量配置 |
| `GEORADIUS` 默认可 Retry | **不正确**，带 `STORE` / `STOREDIST` 会写数据 |
| 未列 `SMEMBERS` | **遗漏**，这是常见只读命令 |
| 未定义如何识别「连接异常」 | 必须补齐，否则现状「任意 Exception 都重试」不会被修掉 |
| 未定义 MULTI / PIPELINE / `rawCommand` | 必须禁止或按真实 Redis 命令名判断 |
| PDO Retry 次数 = 1 | 合理，但需写明相对现状从 4 次收紧 |
| 未把「事务中永不 replay」写成硬规则 | 代码已有，方案必须升格为硬规则 |
| 未说明 reconnect 后仍 throw 的原因 | 连接池 / 长连接需要先愈合连接，再把错误交给业务 |

---

# 3. 核心问题

连接异常不等于服务端没有执行成功。

```text
Client  --command-->  Redis / MySQL
                         │ 执行成功
                         │ response 丢失
Client  <-- Connection Exception
```

如果 Library 此时自动再执行一次，就会产生重复副作用。

典型事故：

```text
INCR order:sequence          → 序号跳号
INSERT INTO user ...         → 重复行
UPDATE account SET balance = balance - 100  → 扣两次
```

完全禁止 Retry 又会损失 `GET` / 普通 `SELECT` 在短暂断线后的自动恢复。因此：

```text
所有允许处理的连接异常都可以 reconnect
reconnect 后是否 replay，必须由 Retry Policy 决定
```

---

# 4. 核心设计原则

## 4.1 Reconnect ≠ Retry

```text
Connection Exception
        │
        ▼
    reconnect()          ← 愈合连接，服务连接池 / 长连接复用
        │
        ▼
    Retry Policy
        │
    ┌───┴────┐
    │        │
 retry    no retry
    │        │
 replay    throw 原异常
 once
```

> 所有确认的连接异常都可以 reconnect；只有能够明确证明重复执行风险可接受的操作，才允许自动 Retry。

## 4.2 只处理连接异常，不处理业务异常

以下错误 **禁止** reconnect，也 **禁止** replay：

```text
WRONGTYPE / wrong type
syntax error
NOSCRIPT
permission denied / NOAUTH
constraint violation / duplicate key
invalid argument
MOVED / ASK（Cluster 重定向，由客户端自己处理，不是断线）
```

死锁（`1213` / `Resource deadlock avoided`）从本方案的「断线重连」路径中拆出：不 reconnect、不按 connection-lost 语义 replay。是否单独做死锁重试不在本次 P0 范围。

## 4.3 白名单，而不是黑名单

```text
明确允许 retry → retry
未配置 / 未知   → 不 retry
```

Redis 命令和 SQL 方言都会增加。默认允许会把「Library 不认识的新写命令」自动 replay。

## 4.4 事务与 MULTI 中永不 replay

| 场景 | 行为 |
|---|---|
| PDO `transTimes > 0` | reconnect（丢掉死连接）+ 标记事务失败 + throw，**不 replay SQL** |
| Redis `MULTI` / `PIPELINE` / `WATCH` 进行中 | reconnect + throw，**不 replay** |

断线后原事务 / MULTI 上下文已经不存在。在新连接上重放其中任何一条命令，都是另一笔操作。

---

# 5. Redis Retry 设计

## 5.1 适用范围

三套 Driver 共用同一套 Retry Policy：

```text
            RedisRetryPolicy
                   │
     ┌─────────────┼─────────────┐
     │             │             │
 PHPRedis       Predis      RedisCluster
```

不能出现同一命令在不同 Driver 上 Retry 行为不一致。

入口一律是 `__call($method, $arguments)`。Policy 看到的是 **PHP 方法名**，必须先规范化成 Redis 命令名：

```text
hGet / hget / HGET     → HGET
rawCommand('INCR', k)  → 取第一个参数 INCR
executeRaw(...)        → 同上
```

`rawCommand` / `executeRaw` 按真实命令名走白名单；无法解析时视为未知命令，不 Retry。

## 5.2 连接异常判定

**PHPRedis / RedisCluster**（按异常类 + message 子串，大小写不敏感）：

```text
read error on connection
Connection lost
Redis server went away
socket error
Connection closed
No connection to persistent
Connection refused
Connection timed out
Broken pipe
reset by peer
php_network_getaddresses
EOF
```

只处理 `RedisException` / `RedisClusterException`。`Error` 与非连接类 message 直接抛出。

**Predis** 只把以下视为连接异常：

```text
Predis\Connection\ConnectionException
Predis\Connection\TimeoutException（若当前 Predis 版本存在）
```

`Predis\Response\ServerException` 禁止 reconnect。

## 5.3 Reconnect 必须恢复的会话状态

| 状态 | 现状 | 本方案 |
|---|---|---|
| `AUTH` password | PHPRedis 已恢复 | 保持 |
| `SELECT` db | **未恢复** | 必须记录并在 reconnect 后重新 `SELECT` |
| Predis `parameters.database` | `new Client(parameters)` 通常会带上 | 保持用原 parameters / options 重建 |
| `setOption`（prefix / serializer） | 丢失 | P0 不做成通用恢复；文档视为已知限制，业务应避免依赖断线后自动恢复这些 option |
| Cluster seeds / auth / persistent | `buildRedisCluster()` 已用原构造参数 | 保持 |

`SELECT` 本身：

- 记录当前 db index
- `SELECT` 命令不放入 replay 白名单（未知即不 replay 也可以）
- 但 **任意命令** reconnect 后都要先恢复 db，再决定是否 replay 那条命令

否则白名单里的 `GET` 也会读错库。

## 5.4 默认允许 Retry 的命令（内置白名单）

只纳入「确定只读、无写选项、无会话游标副作用」的命令。

```text
# String / Bit 读
GET MGET
STRLEN GETRANGE GETBIT BITCOUNT BITPOS
LCS

# Key / TTL 查询
EXISTS TYPE TTL PTTL EXPIRETIME PEXPIRETIME
RANDOMKEY DBSIZE KEYS DUMP OBJECT

# Hash 读
HGET HMGET HGETALL HEXISTS HLEN HKEYS HVALS HSTRLEN HRANDFIELD

# Set 读
SMEMBERS SCARD SISMEMBER SMISMEMBER
SINTER SINTERCARD SUNION SDIFF SRANDMEMBER

# List 读
LLEN LINDEX LRANGE

# ZSet 读
ZCARD ZCOUNT ZRANGE ZRANGEBYSCORE ZREVRANGE ZREVRANGEBYSCORE
ZRANGEBYLEX ZREVRANGEBYLEX ZLEXCOUNT
ZRANK ZREVRANK ZSCORE ZMSCORE ZRANDMEMBER

# HyperLogLog 读
PFCOUNT

# GEO 只读变体（不含可 STORE 的 GEORADIUS）
GEODIST GEOHASH GEOPOS
GEORADIUS_RO GEORADIUSBYMEMBER_RO
GEOSEARCH

# Stream 只读
XLEN XRANGE XREVRANGE XINFO

# 连接探活
PING ECHO TIME LASTSAVE MEMORY
```

说明：

- `SMEMBERS` 必须包含。这是高频只读命令，原方案遗漏。
- `GEORADIUS` / `GEORADIUSBYMEMBER` **默认不 Retry**。它们支持 `STORE` / `STOREDIST`，重复执行会写 key。只放 `_RO` 与 `GEOSEARCH`。
- `SRANDMEMBER` / `HRANDFIELD` / `ZRANDMEMBER` 不修改数据，允许 Retry；两次结果可能不同，可接受。
- `SCAN` / `HSCAN` / `SSCAN` / `ZSCAN` **不 Retry**。cursor 在重连后重放可能漏扫或重复扫描。
- `XREAD` / `XREADGROUP` **不 Retry**。可能阻塞，且 `XREADGROUP` 会改变消费状态。

## 5.5 默认禁止 Retry 的命令（显式列出，避免误加白名单）

```text
# String 写
SET SETEX PSETEX SETNX MSET MSETNX GETSET GETEX GETDEL
APPEND SETBIT SETRANGE
INCR INCRBY INCRBYFLOAT DECR DECRBY

# Key 写
DEL UNLINK EXPIRE PEXPIRE EXPIREAT PEXPIREAT PERSIST
RENAME RENAMENX MOVE COPY TOUCH RESTORE FLUSHDB FLUSHALL

# List 写 / 阻塞弹出
LPUSH LPUSHX RPUSH RPUSHX LPOP RPOP LREM LSET LTRIM
LMOVE BLMOVE LINSERT
BLPOP BRPOP BRPOPLPUSH BZPOPMIN BZPOPMAX

# Set 写
SADD SREM SMOVE SPOP SDIFFSTORE SINTERSTORE SUNIONSTORE

# ZSet 写
ZADD ZREM ZINCRBY ZPOPMIN ZPOPMAX
ZREMRANGEBYRANK ZREMRANGEBYSCORE ZREMRANGEBYLEX
ZINTERSTORE ZUNIONSTORE ZDIFFSTORE

# Hash 写
HSET HMSET HSETNX HDEL HINCRBY HINCRBYFLOAT

# Stream 写 / 消费状态
XADD XDEL XTRIM XACK XCLAIM XAUTOCLAIM XGROUP XREAD XREADGROUP

# GEO 写
GEOADD GEORADIUS GEORADIUSBYMEMBER GEOSEARCHSTORE

# HyperLogLog 写
PFADD PFMERGE

# Lua / 事务 / 订阅
EVAL EVALSHA SCRIPT
MULTI EXEC DISCARD WATCH UNWATCH PIPELINE
SUBSCRIBE PSUBSCRIBE SSUBSCRIBE UNSUBSCRIBE PUNSUBSCRIBE
PUBLISH SPUBLISH

# 其它
BITOP BITFIELD
SELECT SWAPDB
MIGRATE RESTORE-ASKING
```

`SET` 即使看起来像幂等，也带 `NX` / `XX` / `GET` / `EX` / `KEEPTTL` 等语义，默认不 Retry。若业务确认安全，只能通过配置 `extra_commands` 显式打开。

`EVAL` / `EVALSHA` 内部可执行任意写命令，Library 无法判断脚本幂等，默认禁止。

未知命令：默认 no retry。

## 5.6 MULTI / PIPELINE

PHPRedis 的 `multi()` / `pipeline()` 之后，后续 `__call` 只是把命令入队。如果入队或 `exec` 时断线：

```text
reconnect 后的连接不在 MULTI 中
若 replay INCR → 变成独立写命令
```

因此：只要连接处于 MULTI / PIPELINE / WATCH 中，任何命令都不 Retry。reconnect 后 throw。

## 5.7 Redis 配置

内置白名单始终生效；用户配置只做开关、覆盖或增量，避免业务把默认列表抄成 5 个命令后丢失 `SMEMBERS` 等。

```php
[
    'retry' => [
        'enabled' => true,
        'max_times' => 1,
        'delay' => 0.5,
        // 不填则使用内置白名单
        // 'commands' => ['GET', 'MGET'],
        // 在内置（或 commands 覆盖结果）之上追加
        'extra_commands' => [
            // 'SET',
        ],
    ],
]
```

| 配置 | 含义 |
|---|---|
| `enabled = false` | 断线只 reconnect，任何命令都不 replay |
| `enabled = true` | 按白名单决定是否 replay |
| `max_times` | 额外 replay 次数，默认 `1`。不允许无限重试 |
| `delay` | reconnect 前等待秒数，默认 `0.5`，兼容当前行为；仅连接异常路径使用 |
| `commands` | 若提供非空数组，**替换**内置白名单 |
| `extra_commands` | 追加到最终白名单，例如显式打开 `SET` |
| 未出现在最终白名单 | 不 Retry |

PHPRedis 当前没有统一 config 数组（连接参数在 `connect()` 里）。实现时在 `RedisConnection` 增加 `setRetryOptions()` / 默认值即可，不必强行改 `connect()` 签名。

---

# 6. PDO Retry 设计

## 6.1 总流程

```text
SQL
 │
 ▼
execute
 │
 ├── success ────────→ return
 │
 ▼
connection exception ?
 ├── no  → throw（含死锁、语法错误、约束冲突）
 ▼ yes
reconnect()              ← 愈合连接；事务中同时复位 transTimes
 │
 ▼
处于事务？或 SQL 不可 Retry？
 ├── yes → throw 原异常
 ▼ no
replay once
 ├── success → return
 └── fail    → throw
```

`query()` 与 `execute()` 都走 `PDOStatementHandle()`。Retry 必须以 **SQL 文本** 分类，不能用调用方法名判断。业务完全可以 `execute("SELECT ...")`。

## 6.2 SQL 类型与默认 Retry

| SQL 类型 | 默认 Retry |
|---|---|
| 普通 SELECT | 是 |
| SHOW / DESC / DESCRIBE | 是 |
| SELECT FOR UPDATE / FOR SHARE / LOCK IN SHARE MODE / FOR NO KEY UPDATE / FOR KEY SHARE | 否 |
| SELECT INTO OUTFILE / INTO DUMPFILE | 否 |
| WITH（CTE，不解析后续主句） | 否 |
| EXPLAIN（含 EXPLAIN ANALYZE，PG 会真正执行） | 否 |
| INSERT | **永远否** |
| UPDATE | 否 |
| DELETE | 否 |
| REPLACE | 否 |
| CALL / EXEC / EXECUTE | 否 |
| DDL：CREATE / ALTER / DROP / RENAME / TRUNCATE | 否 |
| LOAD / HANDLER / DO / SET / USE | 否 |
| UNKNOWN | 否 |

INSERT 是硬规则：有无 `UNIQUE KEY`、是否 `INSERT ... SELECT`、是否 `ON DUPLICATE KEY UPDATE`，一律不 Retry。幂等由业务保证。

UPDATE 不做表达式解析。`balance = balance - 100`、`NOW()`、`UUID()`、`JOIN`、子查询、`LIMIT` 都可能不安全。当前版本 **全部 UPDATE 不 Retry**。

## 6.3 轻量分类，不做 AST Parser

只做：

1. 去掉前导空白
2. 去掉前导注释：`/* ... */`、`-- ...`、`# ...`
3. 去掉前导左括号（兼容 `(SELECT ...)`）
4. 取第一个关键字（大小写不敏感）
5. 若是 `SELECT`，再用子串检测锁 / 导出子句

不做 JOIN / 子查询 / 表达式幂等分析。

`WITH cte AS (...) SELECT ...` 若不解析主句，无法区分后面是 SELECT 还是 INSERT。P0 将 `WITH` 视为 UNKNOWN，不 Retry。这会牺牲部分 CTE 查询的自动恢复，但避免把 `WITH ... INSERT` replay 出去。

## 6.4 事务

硬规则：

```text
transTimes > 0
    → 连接异常时 close()（复位 transTimes / 回调）
    → throw
    → 不 replay 任何 SQL
```

即使是普通 SELECT，事务中也不 Retry。新连接上的快照、锁、隔离级别都已经不是原事务。

`beginTransaction()` 在 `transTimes == 0` 时因断线失败：允许 reconnect 后再 `beginTransaction`。这不是业务 SQL replay。

## 6.5 连接异常判定

继续使用 `isBreak()` + `2006` / `2013`，但要从 `breakMatchStr` **移除** 或拆出：

```text
Resource deadlock avoided
```

以及其它明显不是「连接已断开、执行结果未知」的条目。死锁在 autocommit 下语句已回滚，语义不同，不走本方案。

只在 `break_reconnect = true` 时启用 reconnect（保持现有开关）。

## 6.6 Retry 次数

| 路径 | 次数 |
|---|---|
| 业务 SQL replay | **最多 1 次**（现状最多 4 次，本方案收紧） |
| `connect()` 建连 | 保持当前「失败再试一次」 |
| `beginTransaction()` | 可保留现有最多 4 次，因其无 SQL replay |

不允许无限重试。

## 6.7 为何非 Retry 的 SQL 也要先 reconnect 再 throw

Swoole 连接池和长生命周期的 `PDOConnection` 会复用同一对象。断线后如果只 throw、不 close/reconnect，坏连接会被还回池里。

因此 UPDATE 断线后的正确行为是：

```text
UPDATE
 → connection exception
 → reconnect（愈合）
 → 不 replay
 → throw 原异常
```

由业务决定是否补偿。Library 不得假定 UPDATE 幂等。

---

# 7. 统一异常处理流程

## Redis / Predis / RedisCluster

```text
command
   │
   ▼
execute
   │
   ├── success ────────→ return
   │
   ▼
是连接异常？
   ├── no → throw
   ▼ yes
reconnect + 恢复 AUTH / SELECT db
   │
   ▼
MULTI/PIPELINE 中？或命令不在白名单？或 retry.enabled=false？
   ├── yes → throw 原异常
   ▼ no
replay once
   ├── success → return
   └── fail    → throw
```

## PDO

```text
SQL
 │
 ▼
execute
 │
 ├── success ────────→ return
 │
 ▼
是连接异常？
 ├── no → throw
 ▼ yes
reconnect
 │
 ▼
事务中？或 SQL 不可 Retry？
 ├── yes → throw 原异常
 ▼ no
replay once
```

Reconnect 失败时：抛出 reconnect 异常，并 `previous` 保留原异常。

非 Retry 路径必须抛出 **第一次** 的连接异常，不能吞掉。业务需要知道写操作结果不确定。

---

# 8. 日志

允许记录命令名 / SQL 类型 / 次数 / 原因，**不要把完整 SQL、绑定参数、Redis value 打进日志**。

当前 `RedisConnection::log()` 会把 `arguments` JSON 进去，Retry 路径不要沿用这套参数日志。

示例：

```text
redis retry: command=GET attempt=2 reason=connection_error
redis retry skipped: command=INCR reason=non_retryable
pdo retry: sql_type=SELECT attempt=2 reason=connection_error
pdo retry skipped: sql_type=UPDATE reason=non_retryable
pdo retry skipped: sql_type=SELECT reason=in_transaction
```

---

# 9. 测试要求

Policy 用纯函数单测即可，不依赖真 Redis / MySQL。Driver 层可用 mock 异常覆盖流程。

## Redis

| 用例 | 期望 |
|---|---|
| `GET` 遇到连接异常 | reconnect，replay 1 次，成功 |
| `SMEMBERS` 遇到连接异常 | replay 1 次 |
| `INCR` 遇到连接异常 | reconnect，不发第二次 INCR，throw |
| `EVAL` / `EVALSHA` | 不 replay |
| `SET` / `SET NX` | 默认不 replay |
| `GEORADIUS` | 不 replay |
| `rawCommand('INCR', key)` | 不 replay |
| `rawCommand('GET', key)` | 可 replay |
| `hGet` / `HGET` / `hget` | 规范化后按 `HGET` 可 replay |
| WRONGTYPE / ServerException | 不 reconnect、不 replay |
| MULTI 进行中的任意命令 | 不 replay |
| 断线前 `SELECT 2`，之后 `GET` 连接异常 | reconnect 后先 SELECT 2，再 replay GET |
| 未知命令 `JSON.GET` | 不 replay |
| RedisCluster `GET` / `INCR` | 与单机语义一致 |
| Predis `GET` / `INCR` | 与 PHPRedis 语义一致 |
| `retry.enabled = false` | 任何命令都不 replay |
| 第二次仍失败 | throw，不再第三次 |

## PDO

| 用例 | 期望 |
|---|---|
| 普通 SELECT 断线 | reconnect + replay 1 次 |
| `SELECT ... FOR UPDATE` | 不 replay |
| `LOCK IN SHARE MODE` / `FOR SHARE` | 不 replay |
| INSERT | execute 次数 = 1，throw |
| UPDATE / DELETE / REPLACE | 不 replay |
| 前导注释 `/* x */ SELECT ...` | 仍识别为 SELECT，可 replay |
| `WITH ... INSERT` / `WITH ... SELECT` | 均不 replay |
| 事务中 SELECT 断线 | close + throw，不 replay |
| `beginTransaction` 断线 | 允许重试开启事务 |
| 死锁 1213 | 不走断线 reconnect/replay |
| 语法错误 / 约束冲突 | 不 reconnect |
| `query("UPDATE ...")` | 按 SQL 文本禁止 replay |
| replay 最多 1 次 | `reConnectTimes` 不得再打到 4 |

---

# 10. 兼容性与行为变化

正常成功请求行为不变：

```text
正常连接 → 正常执行 → 正常返回
```

相对当前 `library-6.x` 的可见变化（这是 P0 修复，不是新功能）：

| 变化 | 说明 |
|---|---|
| 写命令 / 写 SQL 断线后不再自动成功 | 业务会收到异常，需自行补偿或提示失败。这是修复重复扣款 / 重复插入所必须的 |
| PDO 断线 replay 从最多 4 次变为最多 1 次 | 仅对可 Retry 的 SELECT / SHOW 生效 |
| Redis 业务错误不再被当成断线 | `WRONGTYPE` 等会更快失败，不再 sleep 0.5 秒 |
| 死锁不再走 `isBreak` 盲 replay | 与「结果未知的断线」分开 |
| 事务中断线会 `close()` | 避免坏连接留在对象上 |

以下异常在任何版本都不应因为 Retry Policy 被重放：

```text
syntax error
permission denied
constraint violation
invalid argument
wrong type
```

---

# 11. 实现要点

建议抽两个无 IO 的 Policy，三套 Redis Driver 和 `PDOConnection` 只负责「判连接异常 → reconnect → 问 Policy → 决定 replay / throw」。

```text
src/Redis/RedisRetryPolicy.php
src/Db/SqlRetryPolicy.php
```

修改文件：

```text
src/Redis/RedisConnection.php    共享 retry 配置、SELECT db、连接异常匹配、日志
src/Redis/Redis.php
src/Redis/Predis.php
src/Redis/RedisCluster.php
src/Db/PDOConnection.php         PDOStatementHandle / isBreak / 事务断线 close
src/Db/README.md                 补充 break_reconnect 与 SQL retry 语义（实现时再改）
```

不在本次范围：

- 分布式幂等 / Request ID / 业务状态表
- SQL AST Parser / UPDATE 表达式幂等判断
- Redis Lua 幂等包装
- 事务恢复 / 把死事务接续到新连接
- 死锁自动重试
- 完整恢复 Redis `setOption`

---

# 12. 最终硬规则

```text
1. 先 reconnect，再问 Retry Policy；Reconnect ≠ Retry。

2. 只处理连接异常。业务错误、死锁、MOVED/ASK 不走本路径。

3. Redis：内置只读白名单；未知命令默认不 Retry。
   PHPRedis / Predis / RedisCluster 语义完全一致。

4. Redis：EVAL / EVALSHA / MULTI / PIPELINE / rawCommand 写命令不 Retry。
   reconnect 必须恢复 AUTH 与 SELECT db。

5. PDO：INSERT 永远不 Retry。
   UPDATE / DELETE / REPLACE / DDL / CALL 不 Retry。
   仅普通 SELECT / SHOW / DESC 可 Retry 一次。

6. 事务中（PDO transTimes > 0）任何 SQL 都不 Retry。

7. 业务 SQL / Redis 命令最多 replay 1 次。
```

最终原则：

> **Library 可以负责恢复连接，但不能擅自判断业务操作具有幂等性。只有能够明确证明重复执行风险可接受的操作，才允许自动 Retry。**
