# library-6.x Db 模块 Bug 审查

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块 | Db（`PDOConnection`、`Query`/`BaseQuery`、`Model`、`TenantLineInterceptor` 等） |
| 审查日期 | 2026-10-14 |
| 审查范围 | `src/Db/**/*.php` |
| 核实方式 | 逐条对照源码；可复现的问题用 SQLite 内存库 + PDO 桩实际执行验证 |
| 修复日期 | 2026-10-14（修复说明见第 10 节） |
| 回归测试 | `tests/Db/DbBugFixTest.php`（19 个用例） |

结论分三类：

- **已复现**：写了脚本实际跑出错误结果。
- **代码确认**：依赖 MySQL/真实断线等环境不便复现，但从代码路径可以确定。
- **已降级 / 不成立**：初版列出、复核后发现影响很小或实际不会触发。

---

## 2. 问题总览

| 等级 | ID | 问题 | 核实结果 | 状态 |
|---|---|---|---|---|
| P0 | DB-1 | `transactionXa` 各阶段 XA id 不一致，XA 事务无法工作 | 已复现 | 已修复 |
| P0 | DB-2 | `afterCommitCallback` 在事务回滚后不清理，泄漏到下一笔事务提交时执行 | 已复现 | 已修复 |
| P0 | DB-3 | `commit()` / `rollback()` 自身抛异常后 `transTimes` 不复位，连接对象卡在"事务中" | 已复现（PDO 桩） | 已修复 |
| P1 | DB-4 | `cursor()` 迭代中执行其它 SQL，游标串到新语句的结果集 | 已复现 | 已修复 |
| P1 | DB-5 | `multiInsert()` 各行 key 顺序不同时列值错位（静默写错数据） | 已复现 | 已修复 |
| P1 | DB-6 | `batchInsert()` 把 `timestamp` 列值强转 int；`null` 值触发 `TypeError` | 已复现 | 已修复 |
| P1 | DB-7 | 租户拦截器对复杂 SQL 形态不追加条件；`TenantScope` 与拦截器在 handler 缺失时行为不一致 | 代码确认（已知限制） | 部分修复（增加告警日志） |
| P2 | DB-8 | 业务吞掉事务内连接异常后，`commit()` 静默返回，后续 SQL 以 autocommit 执行 | 代码确认 | 已修复 |
| P2 | DB-9 | `beginTransaction` 重试与 SQL replay 共用 `reConnectTimes` | 代码确认 | 已修复 |
| P2 | DB-10 | 持久化 UPDATE 主键为空时用 `WHERE pk = 0` 兜底 | 代码确认 | 已修复 |
| P2 | DB-11 | 基类 XA 方法为空实现，非 MySQL 调 `transactionXa` 无效果 | 代码确认 | 已修复 |
| P2 | DB-12 | `Pgsql` / `Sqlite::getFields` 表名直接拼接 SQL | 代码确认 | 已修复 |
| P2 | DB-13 | `cursor()` 用 `while ($row = fetch())`，`FETCH_COLUMN` 遇到 `0` / `''` 提前结束 | 代码确认 | 已修复 |
| P2 | DB-14 | `getRealSql()` 占位符替换不精确（仅影响调试日志） | 代码确认 | 已修复 |

---

## 3. P0 详情

### DB-1：`transactionXa` XA id 不一致

**位置**：`src/Db/PDOConnection.php` `transactionXa()`、`getUniqueXid()`

`transactionXa` 在 start / prepare / commit / rollback 每个阶段都调用 `$connection->getUniqueXid('_' . $xid)`，而 `getUniqueXid()` 每次都执行 `uniqid('xa', true)`：

```php
public function getUniqueXid(string $suffix = ''): string
{
    return uniqid('xa', true) . $suffix;
}
```

于是 `XA START 'a'`、`XA END 'b'`、`XA PREPARE 'b'`、`XA COMMIT 'c'` 用的是不同 id，MySQL 会报 `XAER_NOTA`，XA 事务不可用。

**复现**：连续两次调用 `getUniqueXid('_x')`，结果不相等。

**修复方向**：每个 connection 在 `transactionXa` 开头只生成一次 xid，各阶段复用。

---

### DB-2：`afterCommitCallback` 回滚后泄漏

**位置**：`src/Db/PDOConnection.php` `commit()`、`rollback()`；调用方 `src/Db/Model.php`（insert / update / delete 注册 `onAfter*CommitCallBack`）

- `rollback()` 只执行并清空 `afterRollBackCallbacks`，不清 `afterCommitCallbacks`；
- `commit()` 在 `transRollback` 分支同样不清 `afterCommitCallbacks`；正常提交分支也不清 `afterRollBackCallbacks`。

同一个连接对象（Swoole 连接池会复用）的**下一笔无关事务**提交时，会执行上一笔已回滚事务注册的回调。例如 Model 在事务中 insert 后回滚，`onAfterInsertCommitCallBack`（常见用途：发消息、清缓存）仍会在之后某次提交时触发。

**复现**：

```php
$db->beginTransaction();
$db->afterCommitCallback(fn() => $fired[] = 'tx1-commit-cb');
$db->rollback();
$db->beginTransaction();
$db->commit();
// $fired === ['tx1-commit-cb']
```

**修复方向**：最外层事务结束（无论提交还是回滚）时同时清空两类回调。

---

### DB-3：`commit()` / `rollback()` 抛异常后事务计数卡住

**位置**：`src/Db/PDOConnection.php` `commit()`、`rollback()`

两个方法都在调用 `PDOInstance->commit()` / `rollBack()` **之后**才递减 `transTimes`。如果连接在业务执行期间断开（例如超过 `wait_timeout`），`commit()` 和随后的 `rollback()` 都会抛 PDOException，`transTimes` 永远停在 1，回调也不清理。

连接对象回到连接池后，下一个请求：

- `isEnableTransaction()` 返回 true，`Model::transaction()` 认为已在事务中，不再 begin/commit；
- `beginTransaction()` 走嵌套分支，只加计数，不真正开启事务；
- 后续 SQL 失去事务保护，且连接异常时被当成"事务中"而拒绝 SELECT replay。

**复现**：用 `commit()` / `rollBack()` 必抛 "server has gone away" 的 PDO 桩，执行 begin → commit（抛）→ rollback（抛）后，`isEnableTransaction()` 仍为 `true`。

**修复方向**：最外层 `commit()` / `rollback()` 失败时 `close()` 连接（复位计数与回调）后再抛出；或 `try/finally` 保证最外层计数归零。

---

## 4. P1 详情

### DB-4：`cursor()` 串结果集

**位置**：`src/Db/PDOConnection.php` `cursor()`

```php
$this->PDOStatementHandle($sql, $bindParams);
while ($result = $this->PDOStatement->fetch($fetchType)) {
    yield $result;
}
```

每次循环读取的是 `$this->PDOStatement` 属性。调用方在 `foreach` 体内用同一连接执行任何 SQL（"边遍历边更新"很常见），属性就被替换成新语句，游标开始读新语句的结果或提前结束。

**复现**：表中 3 行，遍历时循环体内执行 `SELECT 'other'`，最终只拿到 1 行。

**修复方向**：用局部变量保存 statement：`$stmt = $this->PDOStatementHandle(...); while (($row = $stmt->fetch(...)) !== false)`（同时修复 DB-13）。

---

### DB-5：`multiInsert()` 列值错位

**位置**：`src/Db/PDOConnection.php` `multiInsert()`（`insertAll()` 也走这里）

字段列表取所有行 key 的并集（按首次出现的顺序），每行占位符却按该行自己的 key 顺序生成。key 顺序不同就静默错位；key 集合不同则列数不匹配报 SQL 错误。

**复现**：

```php
$db->multiInsert('t', [['a' => 'A0', 'b' => 'B0'], ['b' => 'B1', 'a' => 'A1']]);
// 第二行实际写入 a='B1', b='A1'
```

**修复方向**：以字段列表为准，按 `$fields` 顺序从每行取值；缺失 key 时抛错。

---

### DB-6：`batchInsert()` 类型转换错误

**位置**：`src/Db/PDOConnection.php` `batchInsert()`

1. `parseFieldType()` 对 MySQL `TIMESTAMP` 列返回 `'timestamp'`，`batchInsert` 把 `'timestamp'` 和 `int` 归为一类做 `(int)` 转换，`'2026-10-14 12:00:00'` 被写成 `2026`。
2. 字符串类列走 `$this->quote($value)`，`quote()` 签名是 `string $string`，值为 `null` 时抛 `TypeError`，无法写入 NULL。int 列的 `null` 会被转成 `0`，同样丢失 NULL 语义。

**复现**：`ts timestamp` 列写入 `'2026-10-14 12:00:00'` 结果为 `2026`；值为 `null` 时抛 `TypeError: quote(): Argument #1 ($string) must be of type string, null given`。

**修复方向**：去掉 `timestamp` 的 int 转换；`null` 直接输出 `NULL`。更好的做法是改成参数绑定。

---

### DB-7：租户隔离盲区

**位置**：`src/Db/Interceptor/TenantLineInterceptor.php`、`src/Db/Concern/TenantScope.php`

- 拦截器是基于正则的轻量改写，类注释已声明复杂 SQL（多租户表 JOIN、子查询、CTE、`DELETE t1 FROM t1 JOIN ...` 等）可能不追加条件。匹配失败时直接 return，**不报错也不记录日志**。
- `TenantScope` 在 `TenantScopeContext::getHandler()` 为 null 时静默不加条件；拦截器在租户表且 tenant id 为空时抛异常（失败关闭）。两条路径策略不一致。

这属于已知限制，但失败方式是"静默放行"，建议至少在改写失败时打 warning 日志，并在文档中列出不支持的 SQL 形态。

---

## 5. P2 详情

### DB-8：吞掉事务内连接异常后 `commit()` 静默返回

**位置**：`src/Db/PDOConnection.php` `handleStatementConnectionException()`、`free()`、`commit()`

事务中发生连接异常时，`close()` 会把 `transTimes` 置 0，然后**重新抛出异常**。正常的 `try { ... commit } catch { rollback }` 写法不受影响。

只有业务 catch 后继续执行时才有问题：后续 SQL 在新连接上以 autocommit 执行，最后的 `commit()` 因 `transTimes <= 0` 直接 return，部分数据已经提交且没有任何提示。

初版列为 P0，复核后因需要业务吞异常才会触发，降为 P2。建议断线复位时记录"事务已失效"标志，`commit()` 发现该标志时抛异常。

### DB-9：`reConnectTimes` 共用

**位置**：`src/Db/PDOConnection.php` `beginTransaction()`、`handleStatementConnectionException()`

`beginTransaction` 连续失败后 `reConnectTimes` 可能停在 4，下一条 SELECT 第一次遇到断线时会被判定为 `retry_exhausted` 不重放。任何一次 SQL 成功都会清零，影响有限。建议 begin 重试使用独立计数。

### DB-10：主键为空时 `WHERE pk = 0`

**位置**：`src/Db/Concern/QueryPersistence.php` `applyPersistenceWhere()`、`reloadByPrimaryKey()`

UPDATE 只在 `isExists()` 为 true 时执行，通常主键已加载；只有加载时未选主键字段等情况才会出现空主键。`WHERE pk = 0` 一般匹配不到任何行，但会让 `save()` 静默返回成功（影响 0 行）。建议与 delete 路径一致：空主键直接抛异常。

### DB-11：基类 XA 空实现

**位置**：`src/Db/PDOConnection.php` `startTransXa` 等四个方法

只有 `Mysql` 实现了 XA，`Pgsql` / `Sqlite` / `Oracle` 调用 `transactionXa` 时不会执行任何 XA 语句，回调里的 SQL 以 autocommit 执行。建议基类抛 "not supported" 异常。

### DB-12：表名拼接

**位置**：`src/Db/Pgsql.php` `getFields()`、`src/Db/Sqlite.php` `getFields()`

表名一般来自 Model 配置，不是用户输入，实际风险低。作为加固项：校验表名只含 `[\w.]`。

### DB-13：`cursor()` 遇假值提前结束

**位置**：`src/Db/PDOConnection.php` `cursor()`

使用 `PDO::FETCH_COLUMN` 时，值为 `0`、`'0'`、`''` 的行会让 `while` 提前退出。改为 `!== false` 判断。

### DB-14：`getRealSql()` 替换不精确

**位置**：`src/Db/PDOConnection.php` `getRealSql()`

命名占位符用 `strpos` 替换，`:a` 会命中 `:ab` 的前缀；找不到占位符时 `strpos` 返回 false 会替换掉 SQL 第一个字符。只影响调试日志和慢 SQL 记录，不影响执行。

---

## 6. 初版中复核后不成立的条目

| 初版条目 | 复核结论 |
|---|---|
| `Mysql::getFields` 缓存空数组后永不刷新 | 不成立。`SHOW FULL COLUMNS` 对不存在的表会抛异常，真实表至少有一列，不会缓存空数组 |
| `getSchemaInfo` 使用 `!strpos(...)` | 不成立。只有表名以 `.` 开头才会误判，这不是合法表名 |
| `insertAll` 分批事务与外层事务嵌套 | 不成立。内层失败时 `transRollback` 标记会让外层整体回滚，语义正确 |
| `Query::chunk` 依赖唯一排序列 | 不算 Bug。默认按主键分批；传非唯一列是使用约束，与 ThinkORM 行为一致 |

---

## 7. 测试情况

- 复现场景已转成 PHPUnit 用例：`tests/Db/DbBugFixTest.php`，基于 `Sqlite` 内存库与 PDO 桩，不依赖 MySQL，19 个用例全部通过。
- `tests/Db/Order.php`、`tests/Db/User.php` 原先把 `$table` 声明为非 static，PHP 8.4 下 Fatal，已改为 `protected static $table`，`tests/Db/` 可以全量运行。
- `tests/Db/` 全量仍有 12 个失败，修复前后完全一致，与本次修复无关：
  - `DbTest` 8 个：依赖真实 MySQL 表结构，以及已不存在的 `Swoolefy\Library\Db\SqlBuilder`；
  - `TenantLineInterceptorTest::testDemoHandlerCastsIntTenantIdToString`：依赖框架类 `Swoolefy\Core\Coroutine\Context`；
  - `ModelPersistenceTest::testInsertUpdateDeleteThroughQueryBuilder`、`TenantLineInterceptorTest` 2 个断言失败：修复前已存在，需单独排查。

---

## 8. 修复优先级

1. **P0**：DB-2（回调泄漏，会触发错误的副作用）、DB-3（连接池中事务状态卡死）、DB-1（XA 完全不可用；如果业务没有使用 XA 可以稍后处理）。
2. **P1**：DB-5、DB-6（静默写错数据）、DB-4（cursor 串结果）。
3. **P2**：其余条目按需处理。

---

## 9. 修订记录

| 日期 | 说明 |
|---|---|
| 2026-10-14 | 初版：Db 模块静态审查 |
| 2026-10-14 | 复核：新增 DB-2/3/4/5/6，降级初版 P0-2/P0-3，删除 4 条不成立条目，全部 P0/P1 附复现结果 |
| 2026-10-14 | 修复 DB-1 ~ DB-14（DB-7 为部分修复），新增回归测试 `tests/Db/DbBugFixTest.php` |

---

## 10. 修复说明

| ID | 文件 | 修复方式 |
|---|---|---|
| DB-1 | `PDOConnection::transactionXa()` | 每个 connection 在开头生成一次 xid，START / PREPARE / COMMIT / ROLLBACK 复用；只对已 START 成功的分支回滚，单个分支回滚失败不影响其它分支，最终抛出原始异常 |
| DB-2 | `PDOConnection::commit()` / `rollback()` | 最外层事务结束时统一调用 `resetTransactionState()` 清空两类回调；先快照回调、复位状态，再执行回调 |
| DB-3 | `PDOConnection::commit()` / `rollback()` | 最外层 commit / rollBack 以及内层 savepoint 回滚失败时 `close()` 丢弃连接（计数、回调一并复位）后再抛出。断开连接后数据库会自动回滚未提交事务 |
| DB-4 / DB-13 | `PDOConnection::cursor()` | 使用 `PDOStatementHandle()` 返回的局部 statement 迭代，循环条件改为 `!== false` |
| DB-5 | `PDOConnection::multiInsert()` | 以第一行字段为准，按同一顺序从每行取值；字段集合不一致时抛 `DbException`；占位符改为 `:mi_{行}_{列}`，不再依赖字段名 |
| DB-6 | `PDOConnection::batchInsert()` | 去掉 `timestamp` 的 int 转换；`null` 输出 `NULL` |
| DB-7 | `TenantLineInterceptor` | UPDATE / DELETE 无法识别目标表时，通过新增的 `PDOConnection::recordWarningLog()` 记录 `tenant interceptor skipped`（只记语句类型，不记 SQL）。复杂 SQL 仍不改写，属已知限制。`TenantScope` 的 handler 只有注册了 `TenantLineInterceptor` 才存在，两条路径实际一致，不做改动 |
| DB-8 | `PDOConnection` | 新增 `transactionBroken` 标志：事务中连接异常时置位；此后 `commit()` 抛 `DbException`；`rollback()` 或下一次最外层 `beginTransaction()` 清除 |
| DB-9 | `PDOConnection::beginTransaction()` | 改为循环 + 局部计数 `$attempts`（最多 4 次），不再读写 `reConnectTimes` |
| DB-10 | `QueryPersistence` | 新增 `hasPersistencePkValue()` 直接检查 `_data`（`getPkValue()` 缺失时返回 0，无法区分）。UPDATE 主键为空抛 `DbException`；`reloadByPrimaryKey()` 主键为空时返回 null，不再查询 |
| DB-11 | `PDOConnection` XA 四个方法 | 基类抛 `DbException('XA transaction is not supported by ...')`，只有 `Mysql` 实现 |
| DB-12 | `Pgsql` / `Sqlite::getFields()` | 新增 `normalizeMetaTableName()`：去掉标识符引号后只允许 `[A-Za-z0-9_.]`，否则抛 `DbException` |
| DB-14 | `PDOConnection::getRealSql()` | 命名占位符用 `(?!\w)` 精确匹配；`?` 找不到时跳过 |

### 行为变化（需要业务留意）

- 事务内发生连接异常且业务吞掉异常后，`commit()` 由静默返回改为抛 `DbException`。
- 非 MySQL 驱动调用 `transactionXa()` 由静默无效果改为抛 `DbException`。
- `multiInsert()` / `insertAll(string $table, ...)` 各行字段集合不一致时抛 `DbException`。
- Model 已存在但主键值为空时 `save()` 更新抛 `DbException`。

### 遗留限制

- 支持 savepoint 的驱动（`Sqlite` / `Pgsql` / `Oracle`）中，内层 savepoint 回滚**不会**撤销该层注册的 `afterCommitCallback`，外层提交时仍会执行。要按层撤销需要为每层维护回调栈，属于设计改动，本次未做。
