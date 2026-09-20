# Library 6.x Redis / PDO 自动重连与安全 Retry 技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 方案范围 | Redis / Predis / PDO 自动重连与 Retry |
| 优先级 | P0 |
| 目标 | 消除连接异常后的危险重复执行，同时保留安全操作的自动恢复能力 |
| 核心原则 | Reconnect 与 Retry 解耦 |
| 设计目标 | 不引入复杂幂等系统，不增加业务层功能 |

---

# 2. 背景

当前 `library-6.x` 中 Redis、Predis、PDO 均存在类似的处理模式：

```text
执行操作
   ↓
发生连接异常
   ↓
关闭旧连接
   ↓
重新建立连接
   ↓
再次执行原操作
```

这种处理方式存在一个核心问题：

> 连接异常并不等于服务端没有执行成功。

在分布式网络环境中，可能出现：

```text
Client
   │
   │ command
   ▼
Redis / MySQL
   │
   │ 执行成功
   ▼
Server
   │
   X
   │ response 在返回过程中丢失
   ▼
Client
```

客户端最终看到：

```text
Connection Exception
```

但真实情况可能是：

```text
操作已经成功执行
```

如果 Library 此时自动重新执行一次：

```text
第一次：成功
第二次：retry
```

就会产生重复副作用。

---

# 3. 核心问题

## 3.1 Redis

例如：

```redis
INCR order:sequence
```

第一次执行成功：

```text
sequence = 100
```

但是返回结果之前连接断开。

客户端收到：

```text
RedisException
```

当前实现 reconnect 后再次执行：

```redis
INCR order:sequence
```

结果：

```text
sequence = 101
```

客户端虽然只调用了一次 `INCR`，Redis 实际执行了两次。

---

## 3.2 PDO

例如：

```sql
UPDATE account
SET balance = balance - 100
WHERE id = 1;
```

第一次 SQL 已经成功执行：

```text
balance = 900
```

但 MySQL 响应返回之前连接断开。

PDO 抛出：

```text
PDOException
```

Library 自动 reconnect 后再次执行：

```sql
UPDATE account
SET balance = balance - 100
WHERE id = 1;
```

最终：

```text
balance = 800
```

一次业务操作被执行两次。

---

# 4. 核心设计原则

## 4.1 Reconnect 与 Retry 必须解耦

这是本方案最核心的原则：

```text
Reconnect ≠ Retry
```

连接异常发生以后：

```text
Connection Exception
        │
        ▼
    reconnect()
        │
        ▼
    Retry Policy
        │
    ┌───┴────┐
    │        │
 retry    no retry
    │        │
 replay    throw
```

即：

> 所有允许处理的连接异常都可以进行 reconnect，但 reconnect 后是否重新执行原操作，必须由 Retry Policy 决定。

---

# 5. 为什么不能“所有异常都不 Retry”

完全禁止 Retry 虽然安全，但会损失一部分自动恢复能力。

例如：

```redis
GET user:100
```

如果只是网络连接短暂断开：

```text
GET
 ↓
Connection Exception
 ↓
reconnect
 ↓
retry GET
```

因为 `GET` 不修改数据，所以重复读取不会产生业务副作用。

因此本方案不是：

```text
所有异常 → 不 retry
```

而是：

```text
所有异常
   ↓
reconnect
   ↓
只有明确安全的操作才 retry
```

---

# 6. Redis Retry 设计

## 6.1 基本原则

Redis Retry 采用：

> **白名单策略。**

即：

```text
明确允许 retry → retry

未配置 → 不 retry

未知命令 → 不 retry
```

而不是：

```text
不是危险命令 → retry
```

原因是 Redis 命令持续增加，如果采用默认允许：

```text
新命令
 ↓
Library 没有识别
 ↓
自动 retry
```

可能引入新的重复执行风险。

因此：

> **未知 Redis 命令默认禁止 Retry。**

---

# 7. Redis Retry 配置

建议增加 Redis Retry 配置，例如：

```php
[
    'retry' => [
        'enabled' => true,

        'commands' => [
            'GET',
            'MGET',
            'HGET',
            'HMGET',
            'HGETALL',
        ],
    ],
]
```

配置语义：

| 配置 | 含义 |
|---|---|
| `enabled = false` | Redis 操作异常后不自动 Retry |
| `enabled = true` | 根据命令白名单判断是否 Retry |
| `commands` | 明确允许 Retry 的命令 |
| 未出现在 `commands` | 不 Retry |

---

# 8. Redis 默认允许 Retry 的命令

## 8.1 String 读取命令

```text
GET
MGET

STRLEN
GETRANGE
GETBIT
BITCOUNT
BITPOS
```

这些操作不会修改 Redis 数据。

---

# 9. Redis Key / TTL 查询

可以 Retry：

```text
EXISTS
TYPE
TTL
PTTL
EXPIRETIME
PEXPIRETIME
```

以下不属于 Retry：

```text
EXPIRE
PEXPIRE
EXPIREAT
PEXPIREAT
PERSIST
```

因为这些属于修改操作。

---

# 10. Redis Hash 查询

可以 Retry：

```text
HGET
HMGET
HGETALL
HEXISTS
HLEN
HKEYS
HVALS
HSTRLEN
```

以下不属于 Retry：

```text
HSET
HSETNX
HDEL
HINCRBY
HINCRBYFLOAT
```

---

# 11. Redis Set 查询

可以 Retry：

```text
SCARD
SISMEMBER
SMISMEMBER
SINTER
SINTERCARD
SUNION
SDIFF
SRANDMEMBER
```

以下不 Retry：

```text
SADD
SREM
SMOVE
SPOP
```

---

# 12. Redis List 查询

可以 Retry：

```text
LLEN
LINDEX
LRANGE
```

以下不 Retry：

```text
LPUSH
LPUSHX
RPUSH
RPUSHX
LPOP
RPOP
LREM
LSET
LTRIM
LMOVE
BLMOVE
```

---

# 13. Redis Sorted Set 查询

可以 Retry：

```text
ZCARD
ZCOUNT
ZRANGE
ZRANGEBYSCORE
ZRANK
ZREVRANGE
ZREVRANK
ZSCORE
ZMSCORE
```

以下不 Retry：

```text
ZADD
ZREM
ZINCRBY
ZPOPMIN
ZPOPMAX
```

---

# 14. Redis HyperLogLog

可以 Retry：

```text
PFCOUNT
```

以下不 Retry：

```text
PFADD
```

因为 `PFADD` 属于修改操作。

---

# 15. Redis GEO

查询类命令可以 Retry：

```text
GEODIST
GEOHASH
GEOPOS
GEORADIUS
GEORADIUS_RO
GEOSEARCH
```

以下不 Retry：

```text
GEOADD
GEOSEARCHSTORE
```

其中 `GEOSEARCHSTORE` 会写入 Redis，因此不能 Retry。

---

# 16. Redis SET

`SET` 不建议默认 Retry。

虽然：

```redis
SET key value
```

表面上可能是幂等的，但 `SET` 可以包含改变语义的参数：

```redis
SET key value NX
SET key value XX
SET key value EX 10
SET key value PX 1000
SET key value GET
SET key value KEEPTTL
```

例如：

```redis
SET lock:order 123 NX EX 10
```

第一次成功：

```text
OK
```

响应丢失后 Retry：

```text
(nil)
```

最终客户端得到的结果与第一次执行不同。

因此：

```text
SET
 ↓
默认不 Retry
```

如果未来确实存在明确需要，可以通过配置显式开启，但默认不开放。

---

# 17. Redis 自增命令

以下全部禁止 Retry：

```text
INCR
INCRBY
INCRBYFLOAT
DECR
DECRBY
```

例如：

```redis
INCR counter
```

第一次执行：

```text
counter = 101
```

响应丢失后再次执行：

```text
counter = 102
```

会产生明确的数据错误。

---

# 18. Redis String 修改命令

以下不 Retry：

```text
APPEND
SETBIT
SETRANGE
```

例如：

```redis
APPEND key abc
```

执行两次：

```text
abcabc
```

---

# 19. Redis Key 修改命令

以下不 Retry：

```text
DEL
UNLINK

EXPIRE
PEXPIRE
EXPIREAT
PEXPIREAT
PERSIST

RENAME
RENAMENX
```

尤其 `EXPIRE` / `PEXPIRE` 等命令，重复执行可能改变最终 TTL。

---

# 20. Redis List 修改命令

以下不 Retry：

```text
LPUSH
LPUSHX
RPUSH
RPUSHX
LPOP
RPOP
LREM
LSET
LTRIM
LMOVE
BLMOVE
```

例如：

```redis
LPUSH queue message
```

如果第一次成功、响应丢失，再 Retry 会产生重复消息。

---

# 21. Redis Set 修改命令

以下不 Retry：

```text
SADD
SREM
SMOVE
SPOP
```

即使某些 Set 操作在数据层面重复执行可能没有明显问题，也不应该由基础库假设其业务语义安全。

---

# 22. Redis Sorted Set 修改命令

以下不 Retry：

```text
ZADD
ZREM
ZINCRBY
ZPOPMIN
ZPOPMAX
```

特别是：

```text
ZINCRBY
```

属于典型非幂等操作。

---

# 23. Redis Hash 修改命令

以下不 Retry：

```text
HSET
HSETNX
HDEL
HINCRBY
HINCRBYFLOAT
```

尤其：

```text
HINCRBY
HINCRBYFLOAT
```

重复执行会直接产生数据错误。

---

# 24. Redis Stream

默认全部不 Retry：

```text
XADD
XDEL
XTRIM
XACK
XCLAIM
XAUTOCLAIM
```

原因：

- `XADD` 可能产生重复消息。
- `XACK` 涉及消费状态。
- `XCLAIM` / `XAUTOCLAIM` 涉及消息所有权状态。
- 基础库不应该在执行结果未知时自动重放。

---

# 25. Redis Lua

以下命令默认禁止：

```text
EVAL
EVALSHA
```

原因是 Lua 脚本内部可以执行任意 Redis 操作，例如：

```lua
redis.call('INCR', ...)
redis.call('SET', ...)
redis.call('LPUSH', ...)
redis.call('ZADD', ...)
```

Library 无法仅通过 `EVAL` / `EVALSHA` 判断脚本是否幂等。

因此：

```text
EVAL
EVALSHA
    ↓
默认 no retry
```

---

# 26. Redis 最终 Retry 分类

## 默认 Retry

```text
GET
MGET

STRLEN
GETRANGE
GETBIT
BITCOUNT
BITPOS

EXISTS
TYPE
TTL
PTTL
EXPIRETIME
PEXPIRETIME

HGET
HMGET
HGETALL
HEXISTS
HLEN
HKEYS
HVALS
HSTRLEN

SCARD
SISMEMBER
SMISMEMBER
SINTER
SINTERCARD
SUNION
SDIFF
SRANDMEMBER

LLEN
LINDEX
LRANGE

ZCARD
ZCOUNT
ZRANGE
ZRANGEBYSCORE
ZRANK
ZREVRANGE
ZREVRANK
ZSCORE
ZMSCORE

PFCOUNT

GEODIST
GEOHASH
GEOPOS
GEORADIUS
GEORADIUS_RO
GEOSEARCH
```

## 默认不 Retry

```text
SET

INCR
INCRBY
INCRBYFLOAT
DECR
DECRBY

APPEND
SETBIT
SETRANGE

DEL
UNLINK
EXPIRE
PEXPIRE
EXPIREAT
PEXPIREAT
PERSIST
RENAME
RENAMENX

LPUSH
LPUSHX
RPUSH
RPUSHX
LPOP
RPOP
LREM
LSET
LTRIM
LMOVE
BLMOVE

SADD
SREM
SMOVE
SPOP

ZADD
ZREM
ZINCRBY
ZPOPMIN
ZPOPMAX

HSET
HSETNX
HDEL
HINCRBY
HINCRBYFLOAT

XADD
XDEL
XTRIM
XACK
XCLAIM
XAUTOCLAIM

PFADD

GEOADD
GEOSEARCHSTORE

EVAL
EVALSHA
```

## 未知命令

```text
UNKNOWN
   ↓
默认 no retry
```

---

# 27. Predis

Predis 与 PHPRedis 必须保持完全一致的 Retry 语义。

两套 Driver 应该共用相同的 Retry Policy：

```text
                Redis Retry Policy
                       │
             ┌─────────┴─────────┐
             │                   │
         PHPRedis             Predis
             │                   │
             └───────┬───────────┘
                     │
                 retry / throw
```

不能出现 PHPRedis 与 Predis 对同一命令产生不同 Retry 行为。

---

# 28. PDO Retry 设计

PDO 与 Redis 的核心原则相同：

```text
Connection Exception
        ↓
    reconnect()
        ↓
    SQL Retry Policy
        ↓
 retry / throw
```

但 PDO 的默认策略更加严格。

---

# 29. PDO SQL Retry 总规则

| SQL 类型 | 默认 Retry |
|---|---:|
| 普通 SELECT | ✅ |
| SELECT FOR UPDATE | ❌ |
| SELECT LOCK IN SHARE MODE | ❌ |
| INSERT | ❌ |
| UPDATE | ❌ |
| DELETE | ❌ |
| REPLACE | ❌ |
| CALL | ❌ |
| DDL | ❌ |
| TRUNCATE | ❌ |
| UNKNOWN | ❌ |

---

# 30. SELECT

普通 SELECT 可以 Retry：

```sql
SELECT *
FROM user
WHERE id = ?;
```

连接异常：

```text
SELECT
 ↓
connection exception
 ↓
reconnect
 ↓
retry SELECT
```

原因：

```text
SELECT 本身不修改业务数据。
```

---

# 31. SELECT FOR UPDATE

禁止 Retry：

```sql
SELECT *
FROM account
WHERE id = ?
FOR UPDATE;
```

因为 `SELECT FOR UPDATE` 依赖：

- 当前事务
- 当前数据库连接
- 当前事务上下文
- 数据库锁

发生连接断开以后，原事务上下文已经无法保证。

因此：

```text
SELECT FOR UPDATE → no retry
```

---

# 32. LOCK IN SHARE MODE

同样禁止：

```sql
SELECT *
FROM user
WHERE id = ?
LOCK IN SHARE MODE;
```

因为涉及锁语义。

---

# 33. INSERT：一律不 Retry

这是本方案明确的硬规则：

```text
INSERT
 ↓
永远 no retry
```

无论：

```sql
INSERT INTO user (...) VALUES (...);
```

还是：

```sql
INSERT INTO user (...)
SELECT ...
FROM ...
```

都不 Retry。

原因：

```text
第一次 INSERT
    ↓
数据库成功
    ↓
response 丢失
    ↓
第二次 INSERT
    ↓
可能产生第二条记录
```

即使存在 `UNIQUE KEY`，Library 也不能假定所有业务都有唯一键。

因此：

> INSERT 的幂等性属于业务层，不由 Library 自动推断。

---

# 34. UPDATE：默认不 Retry

UPDATE 的最终规则：

```text
UPDATE
 ↓
默认 no retry
```

例如：

```sql
UPDATE account
SET balance = balance - 100
WHERE id = ?;
```

不会因为：

```text
2006
2013
connection lost
server has gone away
```

而重新执行。

处理方式：

```text
UPDATE
 ↓
connection exception
 ↓
reconnect
 ↓
throw exception
```

---

# 35. UPDATE 中尤其不能 Retry 的操作

以下全部属于 `no retry`。

## 字段自身运算

```sql
SET count = count + 1
SET count = count - 1
SET balance = balance + ?
SET balance = balance - ?
SET amount = amount * ?
SET amount = amount / ?
```

## 函数

```sql
SET updated_at = NOW()
SET token = UUID()
SET value = RAND()
```

以及其他函数调用。

## CASE / IF

```sql
SET status =
    CASE
        WHEN ... THEN ...
        ELSE ...
    END
```

或者：

```sql
SET value = IF(...);
```

## 字段引用

```sql
SET a = b
SET a = b + 1
SET a = CONCAT(a, 'xxx')
```

这些都不 Retry。

---

# 36. UPDATE JOIN

禁止 Retry：

```sql
UPDATE user u
JOIN user_profile p
    ON p.user_id = u.id
SET u.name = p.name
WHERE ...;
```

因为第二次执行时 JOIN 结果可能已经发生变化。

---

# 37. UPDATE 子查询

禁止：

```sql
UPDATE user
SET score = (
    SELECT score
    FROM ...
)
WHERE ...;
```

第二次执行时读取到的数据可能已经发生变化。

---

# 38. UPDATE LIMIT

禁止：

```sql
UPDATE user
SET status = 1
WHERE status = 0
LIMIT 100;
```

第一次可能修改 100 条，第二次可能继续修改另外 100 条。

---

# 39. UPDATE ORDER BY

禁止：

```sql
UPDATE user
SET status = 1
WHERE status = 0
ORDER BY id
LIMIT 100;
```

第二次执行可能作用于不同的数据集合。

---

# 40. DELETE

默认不 Retry：

```text
DELETE → no retry
```

例如：

```sql
DELETE FROM user
WHERE id = ?;
```

虽然第二次 DELETE 可能影响 0 行，但数据库还可能存在：

```text
Trigger
Cascade
Audit
其他数据库副作用
```

所以 Library 不自行判断。

---

# 41. REPLACE

禁止：

```sql
REPLACE INTO user (...)
VALUES (...);
```

因为 `REPLACE` 可能包含：

```text
DELETE
+
INSERT
```

重复执行可能产生不同数据库副作用。

---

# 42. CALL

禁止：

```sql
CALL create_order(...);
```

原因：

存储过程内部可能执行：

```text
INSERT
UPDATE
DELETE
事务
其他副作用
```

Library 无法判断。

---

# 43. DDL

以下全部禁止 Retry：

```text
CREATE
ALTER
DROP
RENAME
TRUNCATE
```

包括：

```sql
CREATE TABLE ...
ALTER TABLE ...
DROP TABLE ...
TRUNCATE TABLE ...
```

---

# 44. UNKNOWN SQL

SQL 类型无法明确判断时：

```text
UNKNOWN
 ↓
no retry
```

继续采用安全优先的白名单策略。

---

# 45. 不做 UPDATE SQL Parser

本方案明确：

> 当前版本不增加复杂 SQL Parser。

不做：

```text
UPDATE
 ↓
解析 SQL AST
 ↓
判断字段表达式
 ↓
判断函数
 ↓
判断 JOIN
 ↓
判断子查询
 ↓
判断 LIMIT
 ↓
决定 retry
```

原因：

1. 增加 Library 复杂度。
2. SQL 方言复杂。
3. MySQL SQL 语法边界很多。
4. Parser 本身可能引入新的 Bug。
5. 当前默认 UPDATE 不 Retry 已经可以覆盖最危险场景。

因此：

```text
UPDATE → 默认 no retry
```

就是当前版本最稳妥的策略。

---

# 46. 统一异常处理流程

## Redis

```text
command
   │
   ▼
execute
   │
   ├── success ────────→ return
   │
   ▼
connection exception
   │
   ▼
reconnect
   │
   ▼
Retry Policy
   │
   ├── retryable ──────→ execute once more
   │
   └── non-retryable ──→ throw
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
connection exception
 │
 ▼
reconnect
 │
 ▼
Retry Policy
 │
 ├── retryable ──────→ execute once more
 │
 └── non-retryable ──→ throw
```

---

# 47. Retry 次数

默认 Retry 次数：

```text
1
```

即：

```text
第一次执行
    ↓
connection exception
    ↓
reconnect
    ↓
允许 retry
    ↓
第二次执行
    ↓
成功 → return
失败 → throw
```

不允许无限重试。

对于安全 Retry 操作：

```text
最多一次 replay
```

即可。

---

# 48. Retry 与日志

建议保留 Retry 日志，例如：

```text
redis retry:
command=GET
attempt=2
reason=connection_error
```

PDO：

```text
pdo retry:
sql_type=SELECT
attempt=2
reason=connection_error
```

对于禁止 Retry 的操作，可以记录：

```text
pdo retry skipped:
sql_type=UPDATE
reason=non_retryable
```

但不需要把完整 SQL / 参数全部打入日志，避免敏感数据泄漏。

---

# 49. 测试要求

## 49.1 Redis GET

```text
第一次 GET
 ↓
模拟 connection exception
 ↓
reconnect
 ↓
retry GET
 ↓
成功
```

确认：

```text
执行次数 = 2
```

---

## 49.2 Redis INCR

```text
第一次 INCR
 ↓
模拟 connection exception
 ↓
reconnect
 ↓
禁止 retry
 ↓
throw
```

确认：

```text
不会发送第二次 INCR
```

---

## 49.3 Redis EVAL

```text
EVAL
 ↓
connection exception
 ↓
no retry
```

确认不会重复执行 Lua。

---

## 49.4 PDO SELECT

```text
SELECT
 ↓
connection exception
 ↓
reconnect
 ↓
retry
 ↓
success
```

---

## 49.5 PDO INSERT

```text
INSERT
 ↓
connection exception
 ↓
reconnect
 ↓
no retry
 ↓
throw
```

必须确认：

```text
execute 次数 = 1
```

---

## 49.6 PDO UPDATE

```text
UPDATE
 ↓
connection exception
 ↓
reconnect
 ↓
no retry
 ↓
throw
```

---

## 49.7 SELECT FOR UPDATE

```text
SELECT FOR UPDATE
 ↓
connection exception
 ↓
no retry
```

---

# 50. 兼容性要求

该方案不能改变正常成功请求的行为：

```text
正常连接
 ↓
正常执行
 ↓
正常返回
```

Retry 逻辑只应该在：

```text
明确的 connection exception
```

情况下触发。

以下普通业务异常不能因为 Retry Policy 而重新执行：

```text
syntax error
permission denied
constraint violation
invalid argument
wrong type
```

---

# 51. 最终设计

```text
┌──────────────────────────────────────────────┐
│              Library Retry Policy            │
├──────────────────────────────────────────────┤
│                                              │
│  Connection Exception                        │
│          │                                   │
│          ▼                                   │
│      reconnect()                             │
│          │                                   │
│          ▼                                   │
│      Retry Policy                            │
│          │                                   │
│    ┌─────┴─────┐                             │
│    │           │                             │
│  retry       no retry                        │
│    │           │                             │
│ replay once   throw                          │
│                                              │
└──────────────────────────────────────────────┘
```

## Redis

```text
明确安全的只读命令
        ↓
      Retry

写命令
        ↓
    默认不 Retry

EVAL / EVALSHA
        ↓
    默认不 Retry

未知命令
        ↓
    默认不 Retry

SET
        ↓
    默认不 Retry
    可配置开启
```

## PDO

```text
普通 SELECT
        ↓
      Retry

SELECT FOR UPDATE
        ↓
    不 Retry

INSERT
        ↓
永远不 Retry

UPDATE
        ↓
默认不 Retry

DELETE
        ↓
不 Retry

REPLACE
        ↓
不 Retry

CALL
        ↓
不 Retry

DDL / TRUNCATE
        ↓
不 Retry

UNKNOWN
        ↓
不 Retry
```

---

# 52. 本方案解决的问题

实施以后，以下危险路径被消除：

```text
INCR
 ↓
connection lost
 ↓
reconnect
 ↓
❌ 第二次 INCR
```

```text
INSERT
 ↓
connection lost
 ↓
reconnect
 ↓
❌ 第二次 INSERT
```

```text
UPDATE balance = balance - 100
 ↓
connection lost
 ↓
reconnect
 ↓
❌ 第二次 UPDATE
```

同时保留：

```text
GET
 ↓
connection lost
 ↓
reconnect
 ↓
retry GET
```

这种低风险自动恢复能力。

---

# 53. 最终结论

本次 `library-6.x` P0 修复不需要引入复杂的：

- 分布式幂等
- Request ID
- 业务状态表
- SQL AST Parser
- Redis Lua 幂等包装
- 事务恢复机制

只需要把当前：

```text
connection exception
       ↓
reconnect
       ↓
blind replay
```

修改成：

```text
connection exception
       ↓
reconnect
       ↓
Retry Policy
       ↓
明确安全 → retry once
不安全   → throw
```

最终三个硬规则：

```text
1. Redis：Retry 使用白名单，未知命令默认不 Retry。

2. PDO：INSERT 永远不 Retry。

3. PDO：UPDATE 默认不 Retry。
```

最终原则：

> **Library 可以负责恢复连接，但不能擅自判断业务操作具有幂等性。只有能够明确证明重复执行风险可接受的操作，才允许自动 Retry。**
