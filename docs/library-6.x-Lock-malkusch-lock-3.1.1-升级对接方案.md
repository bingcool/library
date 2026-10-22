# library-6.x Lock 模块对接 malkusch/lock 3.1.1 升级方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块 | Lock（升级前 `src/Lock/**/*.php` 10 个文件 + README；实施后为 `RedisMutex` / `MySQLMutex` / `PostgreSQLMutex` 3 个封装类 + `CoroutineSynchronizedTrait` + README） |
| 文档性质 | 升级对接方案（已实施，验证记录见 §7.4） |
| 依赖变更 | `composer.json:29` `malkusch/lock` `^2.2.0` → `~3.1.0`，实际安装 **v3.1.1**；关联依赖 `predis/predis` `~3.4`（`composer.json:28`，实际 **v3.7.0**） |
| 旧基线 | 现网对接基于 **v2.2.1**（`swoolefy/vendor/malkusch/lock`，`classes/` 目录 + 小写 `malkusch\lock\` 自动加载） |
| 上游类清单差异 | 2.2.1：`LockMutex`/`SpinlockMutex`/`PHPRedisMutex`/`PredisMutex`/`RedisMutex`/`CASMutex`/`TransactionalMutex`/`PgAdvisoryLockMutex`/`NoMutex`/`MySQLMutex`/`MemcachedMutex`/`FlockMutex`/`SemaphoreMutex`；3.1.1：`Malkusch\Lock\Mutex\{AbstractMutex, AbstractLockMutex, AbstractSpinlockMutex, AbstractSpinlockWithTokenMutex, Mutex, FlockMutex, MemcachedMutex, RedisMutex, SemaphoreMutex, MySQLMutex, PostgreSQLMutex, DistributedMutex, NullMutex}` |
| 验证环境 | 容器 `swoolefy-php85-v62`：PHP 8.5.8 / Swoole 6.2.2 / phpredis + predis / pcntl；Redis 服务端 `192.168.60.83:6379`（容器内可直连） |
| 验证方式 | 逐文件通读 `src/Lock` + 3.1.1 vendor 源码对照 + 容器内定向探针脚本（临时脚本不入库），全部结论均为实测，不是静态推断 |
| 关键前提 | library 专供 swoolefy 框架使用；探针统一以 `Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` 复现 swoolefy Worker 的 `hook_flags=2143287295` 运行条件 |
| 复核状态 | **2026-10-22 复核**：`library/composer.lock` = 3.1.1、`swoolefy/composer.lock` = 3.1.1，依赖收口已完成；复核发现 1 处原方案遗漏（swoolefy Redis 包装器解包，见 §5.2），已同步修订本文。**方案已实施并验证通过，结果见 §7.4**。**同日补充**：按要求恢复 2.x 的协程 `synchronized()` 语义，在 `RedisMutex` 中按 3.1.1 API 重写（§5.6），单测与 E2E 已按新实现复跑。**2026-10-22 再次补充**：MySQLMutex / PostgreSQLMutex 也覆盖 `synchronized()`，协程编排抽取为 `CoroutineSynchronizedTrait` 供三个锁类共用；PG 跨协程 PDO 实测可用，单测与 E2E 已复跑 |

**复核修订（2026-10-22）**

- 依赖已收口：library 与 swoolefy 均为 `malkusch/lock 3.1.1`（swoolefy vendor 已是 `src/` 布局）。
- **发现原方案遗漏**：swoolefy 的 `redis`/`predis` 组件返回的是 `Swoolefy\Library\Redis\Redis` / `Predis`（`RedisConnection` **包装器**，见 `swoolefy/Test/Config/component/cache.php:18-31`），并非原生 `\Redis`/`Predis\Client`。3.1.1 的 `RedisMutex::isClientPHPRedis()` 只识别原生 `\Redis|\RedisCluster`，包装器会落入 Predis 分支并以定位参数调用 phpredis 的 `set()`，**静默错用分支**。方案补充：构造函数自动解包 `getRedisInstance()` / `getPredisInstance()`，解包后仍做类型校验。
- 校验规则修正：Redis 锁名只校验「非空」（Redis key 二进制安全，`{}` 是合法的 cluster hash tag，不应拒绝）；`MySQLMutex` 允许 `acquireTimeout = 0`（`GET_LOCK` 的一次性尝试语义），默认值取 3.0；`PostgreSQLMutex` 必须有限超时（拒绝 `INF` 的阻塞模式）。
- 不引入 `Support/MutexOptions`：三处校验逻辑各自内联，避免为三行参数检查增加抽象。

**结论摘要**

1. **3.1.1 是一次彻底重写，不是小版本升级。** 命名空间大小写（`malkusch\lock` → `Malkusch\Lock`）、目录（`classes/` → `src/`）、类集合、构造签名、超时语义、异常类型全部变化。`src/Lock` 现有 10 个文件里 **9 个直接引用已不存在的上游类或方法**，`composer update` 后整个模块在第一次加载时就会 `Class "malkusch\lock\mutex\RedisMutex" not found` 级别的致命错误。
2. **`src/Lock/SynchronizeTrait.php` 文件删除，但协程编排语义保留。** 它是 2.x 时代为了「协程内不阻塞 Worker、defer 保证释放」而写的子协程 + Channel 编排。实测证明 Swoole 6.2 的 `SWOOLE_HOOK_SLEEP`（512，含在 `SWOOLE_HOOK_ALL` 内）已让 `usleep()` 让出协程，上游 `Loop` 自旋等待协程安全；但按业务要求需要保留「回调在子协程执行、defer 释放、结果回传、父协程被取消也不影响释放」的旧行为，因此在 `RedisMutex` 中基于 3.1.1 的 `protected lock()/unlock()` 重写 `synchronized()`（§5.6）。3.x 的 `$key`/`$token` 为 `private`，重写版不再读取这些字段，只用公开的 `lock()/unlock()` 与 token 状态机，因此原 trait 无法照搬、但语义可以完整实现。
3. **超时参数从 1 个变 2 个，且默认语义变危险。** 2.x 的 `$timeout` 同时是「最大等待」和「锁租期」；3.1.1 拆成 `acquireTimeout`（最大等待）与 `expireTimeout`（租期，**默认 `INF`**）。直接照搬上游默认值 = 持锁进程崩溃后 Redis 键永不过期 = **永久死锁**。新对接必须强制有限租期并做前置校验（`acquireTimeout > 0`，见 §5.2）。
4. **`RedisMutex` 不再接受客户端数组。** 2.x 的 `array $redisAPIs`（Redlock 多节点）在 3.1.1 变为单个 `object $client`；多节点 Redlock 改由显式的 `DistributedMutex` 承担。`swoolefy/Test/Config/component/common.php:36,42` 两处传数组的调用点必须改。
5. **4 个后端在 3.1.1 中已消失**：`CASMutex`、`PredisMutex`（并入 `RedisMutex`）、`PgAdvisoryLockMutex`（更名 `PostgreSQLMutex`）、`TransactionalMutex`。`PHPRedisMutex`/`PredisMutex` 两个封装类建议合并为单一 `RedisMutex`，`PgAdvisoryLockMutex` 更名 `PostgreSQLMutex`。
6. **`MySQLMutex` 在 Swoole 协程下会阻塞整个 Worker。** 实测 Swoole 6.2 的 hook 常量集合只有 `SWOOLE_HOOK_PDO_PGSQL`（65536）与 `SWOOLE_HOOK_PDO_SQLITE`（524288），**没有 PDO_MYSQL 的 hook**；`SELECT GET_LOCK(?, ?)` 是同步等待，等待期间该 Worker 上所有协程一起停摆。这是 `MySQLMutex`/`TransactionalMutex` 的固有限制，方案里给出降级与替代建议（§5.5、决策点 D3）。
7. **predis 3.7.0 实测可用。** 上游 `composer.json` 只 `suggest` `predis/predis ^1.1.8 || ^2.0`，但 3.1.1 `RedisMutex::add()` 在 predis 分支使用的定位参数形式 `set($key, $token, 'PX', $ms, 'NX')` 在 3.7.0 上返回 `Status OK` / `NULL` 均正确，锁的 NX 与 PX 语义、`eval(script, numkeys, ...keys, ...args)` 释放脚本、跨客户端（phpredis ↔ predis）互斥全部实测通过（§4）。

---

## 2. `src/Lock` 现状逐文件影响矩阵

| 文件 | 行数 | 当前 2.x 耦合点 | 3.1.1 状态 | 处置 |
|---|---|---|---|---|
| `PHPRedisMutex.php` | 158 | `extends \malkusch\lock\mutex\RedisMutex`；`use malkusch\lock\exception\{LockAcquireException,LockReleaseException}`、`malkusch\lock\util\Loop`；`use Swoolefy\Core\{BaseObject,BaseServer,EventController}`（未使用）；覆写 `add($redisAPI,$key,$value,$expire)` 与 `evalScript($redisAPI,$script,$numkeys,$arguments)`；`private const PREFIX='lock_'` + 自建 `$key`；`acquireLock()/releaseLock()/isCoroutine()` | 基类存在但签名变为 `(object $client, string $name, float $acquireTimeout, float $expireTimeout)`；`add()` 变为 `protected add(string $key, string $value, float $expireTimeout)`，`evalScript()` 变为 `protected evalScript(string $luaScript, array $keys, array $arguments)`；**phpredis 的 `_serialize` + LZF 处理、`px` 毫秒精度已内置上游** | **重写**：只保留构造参数适配与前置校验；删除 `add()`/`evalScript()`/`hasLzfCompression()` 覆写（上游已实现同等逻辑，见 `vendor/malkusch/lock/src/Mutex/RedisMutex.php:105-175`）；删除 3 个无用 `use`；与 `PredisMutex` 合并 |
| `PredisMutex.php` | 47 | `extends \malkusch\lock\mutex\PredisMutex`（**3.1.1 已删除该类**）；`use SynchronizeTrait`；同样的 `PREFIX`/`$timeOut`/`$key` 影子字段 | 上游改为单一 `RedisMutex`，内部用 `isClientPHPRedis()` 分支 | **删除独立实现**，并入 `RedisMutex`（决策点 D1） |
| `SynchronizeTrait.php` | 120 | 自研 `synchronized()`：`$this->lock()` + `goApp()` 子协程 + `Coroutine\Channel` + `Coroutine::defer` 里 `releaseLock()`；`acquireLock()` 调 `$this->acquire($this->key,$this->timeOut)`（**2.x 公开签名**）；`import malkusch\lock\exception\LockReleaseException` | 3.1.1：`acquire(string $key): bool`/`release(string $key): bool` 为 `protected` 且参数个数变化；`$key`/`$token`/`$acquireTs` 为 `private`；子类无法读取键名，`Channel` 超时用 `$this->timeOut` 也已不存在 | **删除文件**；协程编排抽取到 `CoroutineSynchronizedTrait`，基于 `lock()/unlock()` 实现（§5.6），不再需要私有键状态 |
| `TransactionalMutex.php` | 171 | `extends \malkusch\lock\mutex\TransactionalMutex`（**3.1.1 已删除**）；自建 `new Loop($timeout)`（2.x 构造式）；完整重写 `synchronized()` 做 begin/commit/rollback 重放；`use malkusch\lock\util\Loop`、`malkusch\lock\exception\LockAcquireException` | 3.1.1 无同名基类；`Loop` 仍在但用法变为 `(new Loop())->execute($code, $timeout)` | 决策点 **D3**：推荐删除；若保留则改为 `extends AbstractMutex` + 自有实现（约 90 行） |
| `MySQLMutex.php` | 21 | `extends \malkusch\lock\mutex\MySQLMutex`，ctor `(Mysql $mysql, string $name, int $timeout = 0)` → `parent::__construct($mysql->getPdo(), $name, $timeout)` | 基类存在；`$acquireTimeout` 变 `float`；新增**名称长度校验**：超过 `64 - strlen('php-malkusch-lock:')` = 46 字符抛 `InvalidArgumentException`；`getPdo()` 返回 `?\PDO` 而上游要求非空 `\PDO` | **保留并适配**：参数改 `float`、拒绝 `getPdo() === null`、`$timeout` 默认从 `0` 改为有限正值（0 在 `GET_LOCK` 下表示不等待，语义易误用）；补充协程阻塞告警 |
| `PgAdvisoryLockMutex.php` | 21 | `extends \malkusch\lock\mutex\PgAdvisoryLockMutex`（**3.1.1 更名为 `PostgreSQLMutex`**），ctor `(Pgsql $pgsql, string $name)` | `Malkusch\Lock\Mutex\PostgreSQLMutex`，ctor `(\PDO $pdo, string $name, float $acquireTimeout = INF)`；`INF` 走 `pg_advisory_lock` 阻塞模式，有限值走 `Loop` 忙等 `pg_try_advisory_lock` | **重命名为 `PostgreSQLMutex.php`**，ctor 加 `float $acquireTimeout = 5.0`（不使用上游 `INF` 默认，避免无限阻塞），并做 `getPdo()` 非空校验 |
| `CASMutex.php` | 16 | `extends \malkusch\lock\mutex\CASMutex`（**3.1.1 已删除**） | 无对应类 | **删除文件**（不可达的 2.x 遗留物） |
| `MemcachedMutex.php` | 16 | `extends \malkusch\lock\mutex\MemcachedMutex`，空子类直通 | 基类存在，但 ctor 顺序变化：2.x `(string $name, \Memcached $memcached, int $timeout=3)` → 3.1.1 `(string $name, \Memcached $memcached, float $acquireTimeout = 3, float $expireTimeout = INF)` | 空子类无附加值 → **删除文件**，README 直接指向 `Malkusch\Lock\Mutex\MemcachedMutex`（决策点 D2）；容器无 ext-memcached，保留亦无法测试 |
| `FlockMutex.php` | 16 | `extends \malkusch\lock\mutex\FlockMutex`，空子类直通 | 基类存在，ctor `($fileHandle, float $acquireTimeout = INF)`；策略选择变为 `block / pcntl / loop` | 同 **D2**：删除直通壳 |
| `README.md` | 216 | 描述 2.x 依赖、`lock_` 键前缀、数组式构造、`TimeoutException`、`acquireLock()/releaseLock()` 非阻塞用法 | 全部失效 | **重写**（§6.3 列出必须更新的断言） |

补充核对：`src/Db/PDOConnection.php:651 getPdo(): ?PDo` 为 `Mysql`/`Pgsql`（均 `extends PDOConnection`）的 PDO 入口，签名未变，适配层可继续复用；library/swoolefy 中除 `swoolefy/Test/Config/component/common.php:36,42`、`swoolefy/Test/App.php:105`、`swoolefy/Test/Controller/LockController.php:4,31,61` 之外没有其他 `Swoolefy\Library\Lock` 调用点（已 grep 确认），`Uuid`/`RateLimit`/`PubSub` 等模块不依赖 Lock。

---

## 3. 2.2.1 → 3.1.1 API 差异对照（对接必读）

| 维度 | 2.2.1 | 3.1.1 | 迁移动作 |
|---|---|---|---|
| 命名空间 | `malkusch\lock\mutex\*`、`malkusch\lock\exception\*`、`malkusch\lock\util\*` | `Malkusch\Lock\Mutex\*`、`Malkusch\Lock\Exception\*`、`Malkusch\Lock\Util\*` | 全量替换；大小写敏感，Linux 容器不会「碰巧能跑」 |
| 自动加载路径 | `classes/` | `src/` | 无感，但 `swoolefy` 侧必须重新 `composer update` |
| 互斥体基类链 | `Mutex` → `LockMutex` → `SpinlockMutex` | `Mutex` → `AbstractMutex` → `AbstractLockMutex` → `AbstractSpinlockMutex` → `AbstractSpinlockWithTokenMutex` | 子类只覆写 `acquireWithToken()/releaseWithToken()` 或 `lock()/unlock()`；三个封装类通过 `CoroutineSynchronizedTrait` 覆写 `synchronized()` 以保留协程编排（§5.6） |
| 公开 API | `synchronized(callable)` | `synchronized(callable)` + **`check(callable): DoubleCheckedLocking`** | 新增条件锁用法（§5.4） |
| 超时 | 单个 `int $timeout`，同时是最大等待 **和** 租期（`ex = timeout+1`） | `float $acquireTimeout` + `float $expireTimeout`（默认 `INF`） | 必须显式传租期；秒/毫秒精度由 `px` 承载 |
| 异常 | `malkusch\lock\exception\TimeoutException`、`PhpLockException` | `Malkusch\Lock\Exception\{MutexException, LockAcquireException, LockAcquireTimeoutException, LockReleaseException, ExecutionOutsideLockException, DeadlineException}`；`PhpLockException`/`TimeoutException` 已不存在 | 业务侧 `catch (TimeoutException)` 全部改 `catch (LockAcquireTimeoutException)`；`swoolefy/Test/Controller/LockController.php:4` 是现存引用点 |
| 释放失败信息 | 无 | `LockReleaseException::getCodeResult()` / `getCodeException()` 可取回临界区结果/异常 | 新增错误处理路径（实测见 §4-④） |
| Redis 客户端 | 数组（Redlock 多节点）+ 独立 `PHPRedisMutex`/`PredisMutex` | 单 `object $client`（`\Redis`\|`\RedisCluster`\|`Predis\ClientInterface`）；多节点用 `DistributedMutex` | 调用点参数形态改造 |
| 键名 | `lock_{name}` | `php-malkusch-lock:{name}` | **滚动发布期新旧键不互斥**（§7-风险 R2） |
| 自旋等待 | `Loop(int $timeout)` 构造传超时 | `(new Loop())->execute(callable, float $timeout)`，退避 10 ms→500 ms 随机化 | 无需自研，交给上游 |
| 租期越界 | `unlock()` 在**释放前**抛 `ExecutionOutsideLockException` → 键残留至过期 | `release()` 先按 token 删除，再在 `finally` 判定并抛出 | 行为改善，实测残留键为 0（§4-④） |
| PHP 版本 | 无上限 | `>=7.4 <8.6` | PHP 8.6 发布前需换版本策略（§7-风险 R5） |

---

## 4. 容器实测证据（`swoolefy-php85-v62`，2026-10-21）

| # | 探针场景 | 实测结果 | 支撑的方案结论 |
|---|---|---|---|
| ① | `Swoole\Runtime` hook 常量集合 + `usleep` 是否让出协程 | `SWOOLE_HOOK_SLEEP=512`、`SWOOLE_HOOK_ALL=2143287295`；2 协程各 10×`usleep(10ms)` 墙钟 **112 ms**（串行应为 200 ms）；10×100 ms → **1012 ms**（单协程 1 s，说明确为并发让出） | `Loop` 的 `usleep()` 退避在协程内不阻塞 Worker → **删除 `SynchronizeTrait` 的前提成立**；同时证明集合内**无 PDO_MYSQL hook** |
| ② | 两个协程各自独立 `\Redis` 连接抢同一 `RedisMutex('probe_A', 5.0, 30.0)`，临界区 `usleep(200ms)` | 事件序列 `in-c1 → out-c1 → in-c2 → out-c2`，墙钟 **436–465 ms**（两次 200 ms 串行），结束后键集合 `[]` | 3.1.1 原生 `synchronized()` 在 Swoole 协程下互斥正确、无锁泄漏 |
| ③ | 键已被他人占用时 `acquireTimeout=0.3` | `LockAcquireTimeoutException: Lock acquire timeout of 0.3 seconds has been exceeded`，墙钟 **302–317 ms**，临界区未执行 | 超时异常类型/文案；可用于「未拿到锁」分支判定 |
| ④ | 临界区耗时 0.4 s > 租期 0.2 s | `ExecutionOutsideLockException: The code executed for 0.401792 seconds. But the expire timeout is 0.2 seconds...`；`synchronized()` **不返回业务结果**（返回 `null`）；`getCodeResult()` 可取回 `'slow-result'`；结束后无残留键 | 新 API 必须说明「租期越界 → 结果丢失，需从异常取」；业务侧超时需 ≥ 租期 |
| ⑤ | `acquireTimeout=0.0` | **1.6 ms 内直接抛 `LockAcquireTimeoutException`**，即便键无人持有、临界区从未执行 | 3.x 的 `Loop` 在超时 0 时**一次都不尝试** → 我们的封装必须 fail-fast 拒绝 `acquireTimeout <= 0`（§5.2），否则复现「0 = 非阻塞尝试」的误解 |
| ⑥ | phpredis 开启 `Redis::OPT_SERIALIZER = SERIALIZER_PHP` | `synchronized()` 正常返回、释放成功、无残留键 | 上游 `evalScript()` 已做 `_serialize` + LZF 兼容，**我们 2.x 的 `add()`/`evalScript()` 覆写可以整体删除** |
| ⑦ | predis 3.7.0 参数形态 | `set(k,v,'PX',30000,'NX')` → 首次 `Status OK`、重复 `NULL`；`set(k,v,['nx','px'=>...])` 数组形式在 predis 下 **ERR syntax error**（仅适用 phpredis）；`eval(script, 1, key, token)` 正常；关联的 3.1.1 上游 `add()` predis 分支实测可用 | predis 3.7 与上游 3.1.1 兼容（尽管上游仅 `suggest ^1.1.8\|^2.0`）；无需任何自研适配层 |
| ⑧ | 同一 `\Redis` 实例被 2 个协程同时使用 | `Swoole\Error: Socket#N has already been bound to another coroutine` → **进程 fatal（exit 255）** | 硬约束：**mutex 实例与其持有的连接不可跨协程共享**，必须「每协程一个 mutex + 每协程一个池化连接」（§5.3、§6.2） |
| ⑨ | 跨客户端互斥：predis 持锁（TTL 30）时 phpredis 竞争；反向同理 | 两侧均 `LockAcquireTimeoutException`；phpredis 裸 `set(...,['nx','px'])` 返回 `false` | 混合客户端部署（phpredis/predis 并存）互斥可靠；2.x 遗留键 `lock_*` 与新键 **不互斥** → R2 |
| ⑩ | 业务异常路径 | `synchronized()` 抛出 `DomainException` 后键集合 `[]` | 上游 `try/finally` 覆盖 2.x 自研 `Coroutine::defer` 释放逻辑 |
| ⑪ | 锁键命名与 TTL | `RedisMutex(..., 'order_lock', 2.0, 7.0)` 持锁期间键名 `php-malkusch-lock:order_lock`、`TTL=7`、token 长度 64（`bin2hex(random_bytes(16)).md5(...)`） | README 需按新键名描述运维排查方式 |

---

## 5. 目标设计（3.1.1 对接后）

### 5.1 目录结构

```
src/Lock/
├── RedisMutex.php                  # phpredis / RedisCluster / Predis 单客户端锁（合并原 PHPRedisMutex + PredisMutex）
├── MySQLMutex.php                  # 适配 Swoolefy\Library\Db\Mysql
├── PostgreSQLMutex.php             # 适配 Swoolefy\Library\Db\Pgsql（原 PgAdvisoryLockMutex）
├── CoroutineSynchronizedTrait.php  # 三个锁类共用的 synchronized 协程实现
└── README.md                       # 重写
```

删除：`PHPRedisMutex.php`、`PredisMutex.php`、`SynchronizeTrait.php`、`CASMutex.php`、`MemcachedMutex.php`、`FlockMutex.php`、`PgAdvisoryLockMutex.php`，以及（按 D3）`TransactionalMutex.php`。

### 5.2 公共 API 与前置校验

```php
namespace Swoolefy\Library\Lock;

final class RedisMutex extends \Malkusch\Lock\Mutex\RedisMutex
{
    use CoroutineSynchronizedTrait;
    ...
}

final class MySQLMutex extends \Malkusch\Lock\Mutex\MySQLMutex
{
    use CoroutineSynchronizedTrait;

    public function __construct(Mysql $mysql, string $name, float $acquireTimeout = 3.0)
}

final class PostgreSQLMutex extends \Malkusch\Lock\Mutex\PostgreSQLMutex
{
    use CoroutineSynchronizedTrait;

    public function __construct(Pgsql $pgsql, string $name, float $acquireTimeout = 5.0)
}
```

三个封装类都通过 `CoroutineSynchronizedTrait` 覆盖 `synchronized()`（§5.6），不再继承上游的同步实现。

校验规则（遵循项目既有「处理参数前置校验 + 异常包装」规范，以 `InvalidArgumentException` 快速失败）：

- `RedisMutex` 客户端解包优先：`Swoolefy\Library\Redis\Redis` → `getRedisInstance()`；`Swoolefy\Library\Redis\Predis` → `getPredisInstance()`；解包后必须是 `\Redis|\RedisCluster|\Predis\ClientInterface`，否则抛 `InvalidArgumentException`（避免 3.1.1 误判分支）。
- `acquireTimeout <= 0`（Redis）→ 抛 `InvalidArgumentException`。依据 §4-⑤：3.x 下 0 表示「永不尝试」，静默必然超时是最坑的用法。
- `leaseTimeout` 非有限值或 `<= 0` → 抛 `InvalidArgumentException`。上游默认 `INF` 会带来死锁风险（摘要第 3 条）。
- `name` 为空串或纯空白 → 抛 `InvalidArgumentException`；Redis key 二进制安全，不做字符白名单。MySQL 侧的上游 46 字符上限由上游异常透传。
- `MySQLMutex`：`acquireTimeout < 0` 或非有限值 → 抛 `InvalidArgumentException`；`0` 合法（`GET_LOCK(name, 0)` 一次性尝试）。
- `PostgreSQLMutex`：`acquireTimeout` 必须有限且 `> 0`（拒绝 `INF` 的 `pg_advisory_lock` 无限阻塞模式；需要无限等待时直接用上游类）。
- `MySQLMutex`/`PostgreSQLMutex` 的 `$mysql->getPdo()` 为 `null` → 抛 `InvalidArgumentException`（上游 ctor 要求非空 `\PDO`，否则得到的是 `TypeError`，语义不清）。

**不提供** `acquireLock()`/`releaseLock()`/`isCoroutine()`。理由：3.x 的 token 状态机（`$token`/`$acquireTs` 为 `private`）使「一处 acquire、另一处 release」的用法不再安全；公开手工 acquire/release 会重新引入锁泄漏。非阻塞语义改用 §5.4 的标准写法。

### 5.3 使用约束（README + 代码注释必须写明）

1. **一个 mutex 实例只能被一个业务协程独占使用**（§4-⑧）。连接池租借的对象被两个业务协程并发读写会直接 fatal。因此不要把 mutex 做成进程级或跨协程共享单例，也不要在业务层把父协程的 mutex 实例传给另一个并发协程。swoolefy 的组件容器按协程缓存实例，`get()` 得到的即是「每协程一个 mutex」。`synchronized()` 内部「父协程取锁 → 子协程执行/释放」是模块受控交接（§5.6），不属于此限制。
2. `synchronized()` 的回调耗时应显著小于 `leaseTimeout`；否则释放阶段抛 `ExecutionOutsideLockException`，业务结果/异常需从 `getCodeResult()` / `getCodeException()` 取回（§4-④）。
3. `synchronized()` 的回调运行在 `goApp()` 子协程，`Application::getApp()` 是独立的 `EventController`；需要 Redis/DB 时应在回调内重新 `get()`，不要使用父协程闭包捕获的连接或组件 DTO（mutex 自身持有的连接由模块受控交接）。
4. 未拿到锁：`catch (LockAcquireTimeoutException)`。释放失败：`catch (LockReleaseException)`，此时临界区已执行，副作用需按幂等设计。
5. `MySQLMutex` 的 `GET_LOCK` 等待**不被 Swoole hook**（§4-①），等待期间整个 Worker 停摆 → 只在超时极短（≤1 s）且并发量可控时使用；PostgreSQL 需确认已开启 `SWOOLE_HOOK_PDO_PGSQL`，否则同理。
6. Redis 键名为 `php-malkusch-lock:{name}`，运维排查/监控按此键模式统计（§4-⑪）。

### 5.4 推荐调用范式

```php
// 1) 标准临界区
use Swoolefy\Library\Lock\RedisMutex;
use Malkusch\Lock\Exception\{LockAcquireTimeoutException, LockReleaseException};

$mutex = new RedisMutex(
    Application::getApp()->get('redis')->getObject(),  // swoolefy Redis 包装器（构造时自动解包）或原生客户端
    'order_lock',
    acquireTimeout: 5.0,
    leaseTimeout: 30.0
);

try {
    $result = $mutex->synchronized(static fn () => $service->deduct($order));
} catch (LockAcquireTimeoutException $e) {
    return ['tag' => '系统繁忙，请稍后重试'];           // 未获得锁，业务未执行
} catch (LockReleaseException $e) {
    // 业务已执行：$e->getCodeResult()/getCodeException() 决定补偿
    throw $e;
}

// 2) 「拿不到就走」的非阻塞语义（替代旧 acquireLock()/releaseLock()）
try {
    $mutex = new RedisMutex($redis, 'order_lock', 0.05, 30.0);   // 约 2–4 次尝试
    $mutex->synchronized($code);
} catch (LockAcquireTimeoutException) {
    // 未拿到锁
}

// 3) 条件临界区（3.x 新增的双检锁）
$ok = $mutex->check(static fn (): bool => $cache->has($id) === false)
             ->then(static fn () => $loader->fill($id));   // 检查不通过则不加锁，返回 false
```

### 5.5 若需 Redlock 多节点（旧数组式构造的替代）

3.1.1 用显式 `Malkusch\Lock\Mutex\DistributedMutex` 承载 Redlock：

```php
use Malkusch\Lock\Mutex\{DistributedMutex, RedisMutex};

$mutex = new DistributedMutex([
    new RedisMutex($redisA, 'order_lock', 3.0, 30.0),
    new RedisMutex($redisB, 'order_lock', 3.0, 30.0),
    new RedisMutex($redisC, 'order_lock', 3.0, 30.0),
], acquireTimeout: 3.0, expireTimeout: 30.0);
```

本模块**不再自研 `DistributedMutex` 封装**：其构造只需上游类，且当前 library/swoolefy 无任何多节点 Redlock 调用点。README 记录用法即可（避免为假想需求提前抽象）。

### 5.6 `synchronized()` 协程模型（2026-10-22 补充）

按业务要求恢复 2.x `SynchronizeTrait` 的协程行为，但不照搬其实现；协程编排位于 `CoroutineSynchronizedTrait`，由 `RedisMutex` / `MySQLMutex` / `PostgreSQLMutex` 共用：

| 行为 | 2.x `SynchronizeTrait` | 3.1.1 `CoroutineSynchronizedTrait::synchronized()` |
|---|---|---|
| 获取锁 | 调用方协程 `lock()` | 同左，`LockAcquireTimeoutException` 在原调用处抛出 |
| 执行临界区 | `goApp()` 子协程 | `goApp()` 子协程（独立 `EventController` 上下文） |
| 释放锁 | 子协程 `Coroutine::defer` 中 `releaseLock()` | 子协程 `Coroutine::defer` 中 `unlock()`（token + 租期校验由上游完成） |
| 结果回传 | `resultChannel` + 5 次重试，超时返回 `null` | 单一 `outcomeChannel` 等到子协程结束；`null`/`false` 与通道失败可区分 |
| 释放失败 | `catch` 后静默吞掉 | 按上游契约抛 `LockReleaseException`，并 `setCodeResult()`/`setCodeException()` |
| 父协程取消 | 无法感知 | 子协程 defer 仍释放；父协程收到通道失败时抛 `MutexException` |
| 子协程启动失败 | 最多等 `$timeOut` 后返回 `null` | 1 秒启动握手；失败则释放锁并抛 `MutexException`，不会无锁执行回调 |
| 非协程环境 | 不可用 | 回退上游同步实现 |

实现要点（`src/Lock/CoroutineSynchronizedTrait.php`）：

1. `lock()` 在调用方协程执行，成功后创建 `startedChannel`/`outcomeChannel`，`$aborted` 标志防止启动超时后子协程无锁执行。
2. 回调签名保持旧版兼容：`$fx($this)`；零参闭包会忽略额外参数。
3. 子协程先 `startedChannel->push(true)`，再注册 `Coroutine::defer`，最后执行回调；defer 中 `unlock()` + 推送结果。
4. 父协程 1 秒内未收到启动信号且结果通道为空 → 置 `$aborted = true`、释放锁、抛 `MutexException`。
5. 父协程 `pop(-1)` 等到释放完成才返回；返回 `false` 视为父协程被取消，抛 `MutexException`。
6. 连接交接顺序为「父协程取锁 → 父协程挂起 → 子协程独占使用 → 子协程释放 → 父协程恢复」。Redis 的 phpredis/Predis、MySQL 的 PDO、PostgreSQL 的 PDO 均已实测跨协程释放可行（PG 启用 `SWOOLE_HOOK_PDO_PGSQL` 后，父协程创建的 PDO 在子协程查询、释放均正常）。
7. MySQL/PG 的解锁与 Redis token 校验不同：`RELEASE_LOCK` / `pg_advisory_unlock` 是会话级操作，必须使用与加锁相同的 PDO 连接；trait 依赖 mutex 实例持有该连接完成受控交接，业务不得在两个并发协程间共享同一 mutex。

---

## 6. 实施步骤

### 6.1 阶段划分

| 阶段 | 内容 | 产出 |
|---|---|---|
| P0 依赖收口 | ~~`library/composer.json` 改 `~3.1.0`；`composer update`~~ **已完成（2026-10-22）：library 与 swoolefy 均锁定 3.1.1** | 依赖锁定；`grep -rn "malkusch\\\\lock" src/Lock` 结果为 0 |
| P1 模块重写 | **已完成**：新建 `RedisMutex.php`、`PostgreSQLMutex.php`、`CoroutineSynchronizedTrait.php`；改写 `MySQLMutex.php`；删除 `CASMutex`/`FlockMutex`/`MemcachedMutex`/`PHPRedisMutex`/`PgAdvisoryLockMutex`/`PredisMutex`/`SynchronizeTrait`/`TransactionalMutex`；前置校验与契约注释已补 | `src/Lock` 为 3 个封装类 + 1 个共享 trait + README |
| P2 单元测试 | **已完成**：`tests/Lock/LockModuleTest.php` 覆盖 Redis 锁；新增 `tests/Lock/DatabaseLockTest.php` 覆盖 MySQL/PG 的子协程执行、释放后可重入、业务异常透传、获取超时、非协程回退 | 容器内 39 tests / 116 assertions 全绿（MySQL/PG 不可达时 skip） |
| P3 swoolefy 侧对接 | **已完成**：`Test/Config/component/common.php:34-43` 改单客户端构造；`Test/App.php` 的 `getRedisLock()` 改用协程容器 `get()`（同一协程单例）；`LockController` 改用 `LockAcquireTimeoutException`、`locktest2` 改短等待范式，新增 `/api/lock/hold`、`/api/lock/verify`；`LockVerify` 新增 `mysql_synchronized` / `pgsql_synchronized` 场景（数据库不可达时标记 skipped） | E2E `pass: true` |
| P4 文档 | **已完成**：重写 `src/Lock/README.md`；本方案文档随 PR 归档 | 文档与实现一致 |

### 6.2 调用点改造清单（swoolefy）

| 位置 | 现状 | 目标 |
|---|---|---|
| `swoolefy/Test/Config/component/common.php:36` | `new PHPRedisMutex([$redis], 'order_lock', 5)` | `new RedisMutex($redis, 'order_lock', 5.0, 30.0)`；组件实例由协程容器缓存，禁止跨协程传递 mutex（§5.3-1） |
| `swoolefy/Test/Config/component/common.php:42` | `new PredisMutex([$redis], 'order_lock-1', 5)` | `new RedisMutex($redis, 'order_lock-1', 5.0, 30.0)` |
| `swoolefy/Test/App.php:18,103-105` | `use ...Lock\PHPRedisMutex`、注释 `@return PHPRedisMutex\|ContainerObjectDto` | 改 `RedisMutex`；注释同步 |
| `swoolefy/Test/Controller/LockController.php:4` | `use malkusch\lock\exception\TimeoutException` | `use Malkusch\Lock\Exception\LockAcquireTimeoutException` |
| `swoolefy/Test/Controller/LockController.php:31-42` | `synchronized()` + 注释里的 `TimeoutException` catch | 保持 `synchronized()`，catch 换类名；把 `sleep(10)` 调整为「短于租期」并在注释里写明越界后果 |
| `swoolefy/Test/Controller/LockController.php:61-70` | `if ($lock->acquireLock()) { ... $lock->releaseLock(); }` | 改为 §5.4-2 短等待范式（或 `check()->then()`） |

### 6.3 README 必须改写的断言点

- 依赖声明 `malkusch/lock ^2.2` → `^3.1`。
- 类清单表（`CASMutex`、`PredisMutex`、`PHPRedisMutex`、`TransactionalMutex`、`PgAdvisoryLockMutex` 全部移除或更名）。
- 「Redis Key 实际为 `lock_{name}`」→ `php-malkusch-lock:{name}`。
- 「超时抛 `malkusch\lock\exception\TimeoutException`」→ `Malkusch\Lock\Exception\LockAcquireTimeoutException`。
- 「`timeout` 单位秒，同时是等待上限和锁过期时间」→ 拆成 `acquireTimeout`/`leaseTimeout`，并说明 0 值陷阱与租期越界行为。
- `synchronized` 说明：三个封装类共用 `CoroutineSynchronizedTrait`，保留旧版协程语义（获取在调用方、回调和 defer 释放在 `goApp()` 子协程、父协程等到释放完成），并说明非协程环境回退上游实现；同时列出 PDO_MYSQL 未被 hook 的例外。
- 新增「实例不得跨协程共享」与「每协程独占连接」的告警段。

---

## 7. 测试与验收方案

### 7.1 单元测试（`tests/Lock/LockModuleTest.php`）

| 用例组 | 覆盖点 |
|---|---|
| 构造校验 | `acquireTimeout <= 0`、`leaseTimeout` ≤0/INF、非法客户端类型、`getPdo()` 为 null、空/非法锁名 → `InvalidArgumentException` 消息含参数值 |
| 键与 TTL | 持锁期间键名 `php-malkusch-lock:{name}`、`TTL` ≈ `leaseTimeout`、token 为 64 位十六进制混合串 |
| 互斥性 | 2–8 协程（每协程独立连接）串行进入临界区；事件序列满足「同一时刻仅一个 in」 |
| 超时 | 外部占键 → `LockAcquireTimeoutException`；墙钟在 `acquireTimeout ± 150 ms` 内 |
| 租期越界 | 临界区耗时 > 租期 → `ExecutionOutsideLockException`，`getCodeResult()` 返回业务结果，键已被删除 |
| 异常安全 | 业务抛 `DomainException` → 键释放、原异常透传 |
| 驱动矩阵 | phpredis（默认 + `SERIALIZER_PHP` + `COMPRESSION_LZF` 若可用）、predis 3.7、phpredis↔predis 交叉互斥、swoolefy `Redis`/`Predis` 包装器自动解包 |
| 契约层 | `check()->then()` 三种分支（前检失败 / 后检失败 / 通过）；`DistributedMutex` 单节点退化验证 |
| 条件跳过 | 无 ext-memcached / 无 MySQL / 无 PG 服务时按现有套件风格 `markTestSkipped`，不伪造通过 |

### 7.2 swoolefy Test 服务端到端（项目既有约定：库的协程/框架行为除单测外必须在 Test 服务进程实测）

场景（统一 `GET /lock/verify`，输出 `{"pass":bool,"cases":[...]}`，与 PubSub/CurlProxy 验证入口同风格并逐场景加注释）：

1. 同请求内并发协程抢锁（验证 §4-②⑧ 在真实 Worker hook 配置下成立）。
2. 跨 HTTP 请求并发抢锁（curl 并发打 `/api/lock-test1`，验证互斥与 503/繁忙分支）。
3. 租期越界（业务 `sleep` 长于租期 → `ExecutionOutsideLockException` 可观测、无残留键）。
4. 短等待非阻塞范式（未拿到锁走「稍后重试」分支）。
5. predis 与 phpredis 混合持锁/竞争。
6. 业务异常后锁释放。
7. 组件注册形态：同一协程内两次 `get()` 复用同一个 mutex（顺序获取两次均成功），子协程经独立 `EventController` 得到不同实例。
8. 包装器路径：直接用 `Application::getApp()->get('redis')->getObject()`（`Swoolefy\Library\Redis\Redis` 包装器）构造锁，验证自动解包且走 phpredis 分支（对 NX/PX 与释放脚本均生效）。
9. 协程上下文：`synchronized()` 回调的 cid 与父协程不同，且 `Application::getApp()` 为 `EventController`（验证 `goApp()` 路径与独立组件上下文）。

### 7.3 验收标准

- 容器内 `/home/wwwroot/swoolefy/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Lock` 全绿；`tests/CurlProxy`、`tests/PubSub` 复跑无回归。
- `grep -rn "malkusch\\\\lock\|SynchronizeTrait\|acquireLock()\|releaseLock()" library/src library/tests swoolefy/Test --include='*.php'` 结果为 0（小写命名空间与旧 API 彻底清除；README 的迁移说明不参与该检查）。
- swoolefy Test E2E 输出 `pass: true`，且 `Storage/` 与 `swoole.log` 无新增 fatal。
- 临时探针脚本（`library/.lock-probe/`、容器 `/tmp/probe*.php`）全部清理，工作区无残留。

### 7.4 实施与验证结果（2026-10-22）

| 项 | 结果 |
|---|---|
| Lock 单测 | `tests/Lock` **39 tests / 116 assertions** 全绿（容器 PHP 8.5.8 + Swoole 6.2.2）；Redis 覆盖子协程执行、释放时序、null/false 返回值、业务异常透传、租期越界双异常携带、非协程回退；MySQL/PG 覆盖子协程执行、释放后可重入、业务异常透传、获取超时、非协程回退 |
| swoolefy E2E | `GET /api/lock/verify` → **`pass: true`**。默认环境 MySQL/Redis 共 11 个场景全部通过，`pgsql_synchronized` 因 PostgreSQL 未部署标记 skipped；临时 PG 16 容器下 11/11 全部通过（含 pgsql_synchronized） |
| 协程上下文实测 | `synchronized_context`：`parent_cid=2`、`callback_cid=21`、回调内 `Application::getApp()` = `Swoolefy\Core\EventController` |
| 外部并发 | 3 个并发 curl 打 `/api/lock/hold`（hold 300ms），临界区无重叠，总耗时 ≈ 932ms（串行化成立） |
| 高并发压测（2026-10-22） | 协程 HTTP 客户端打 `/api/lock/hold`：50/200/300/400/500 并发分别 **50/50、200/200、300/300、400/400、500/500 全部获锁**，临界区最大并发恒为 **1**，零重叠、零残留；500 并发耗时 9.84s（wait 10s 内）。短等待 100 并发（wait 0.2s）→ 20 获锁 + 80 优雅超时，仍零重叠 |
| 连接池容量 | Test 默认 redis 池 5 + 10 降级/Worker：100 并发即触发池上限 503，且失败发生在进入锁之前；临时扩容降级额度后 200–500 并发通过，配置已还原。生产池容量需覆盖峰值锁等待者数量 |
| 演示接口 | `/api/lock-test1` 正常返回 `[1111]`；并发两请求时一个持锁 10s、另一个在 5s 获取超时后走「锁等待超时」；并发 `/api/lock-test2` 一请求取锁、一请求走「稍后重试」分支 |
| 回归 | `tests/CurlProxy` 26 tests / 73 assertions、`tests/PubSub` 43 tests / 133 assertions 全绿（PubSub 使用 library 自身 phpunit；swoolefy 的 phpunit 会加载其 autoload，使 PubSub 走 `goApp()` 而 CLI 下缺少 `APP_PATH`，属既有测试环境限制，与本次改动无关） |
| 残留检查 | Redis `php-malkusch-lock:*` 无残留键；Test 服务日志无新增 fatal；临时验证脚本与 `/tmp` 产物已清理 |

---

## 8. 风险与回归面

| ID | 风险 | 影响 | 缓解 |
|---|---|---|---|
| R1 | 宿主项目未同步依赖 | `swoolefy/composer.lock` 仍锁 v2.2.1；只更新 library 会在下次 `composer update` 前继续跑旧类，出现「本地能跑、CI 挂」 | P3 明确 `composer update malkusch/lock --with-all-dependencies`，并把「小写命名空间引用为 0」纳入验收 |
| R2 | 滚动发布期新旧键名并存 | 2.x 用 `lock_{name}`，3.1.1 用 `php-malkusch-lock:{name}`；灰度期间两类实例**互不加锁**，可能重复扣款/重复发信 | 发布策略：一次性全量切换（低峰），或先在业务层加双键（临时），或按实例数灰度但接受窗口期风险；本方案默认「全量切换 + 业务幂等兜底」 |
| R3 | 租期默认值变化 | 直接沿用上游 `expireTimeout=INF` 默认 → 进程崩溃/被 kill 时锁永久残留 | 封装层强制有限 `leaseTimeout`，`INF` 视为非法（§5.2） |
| R4 | 协程内阻塞型后端 | `MySQLMutex`/`TransactionalMutex` 依赖未被 hook 的 PDO_MYSQL；等待即占死 Worker；`FlockMutex` 的 pcntl 策略与 Swoole 信号处理存在交互风险 | README 标红 + 优先推荐 Redis 锁；不在 swoolefy 请求路径使用 DB 类锁（D3 删除 `TransactionalMutex` 的核心理由） |
| R5 | 上游 PHP 版本上限 `<8.6` | PHP 8.6 发布后无法升级到该约束内的 lock；当前容器 8.5.8 安全 | 在 README「依赖」节记录约束；届时评估 fork 或替换 |
| R6 | predis 版本超出上游建议范围 | 上游 `suggest ^1.1.8\|^2.0`，实际使用 3.7.0；虽然 §4-⑦ 全项通过，未来 predis 变更 `set()` 参数形态会静默破坏 NX 语义 | 单测固化「重复 `set(...,'PX',$ms,'NX')` 必须返回 null」与交叉互斥两条断言，作为兼容性哨兵 |
| R7 | 删除 `TransactionalMutex`/`CASMutex` 等公共 API | 若有外部业务代码依赖则属破坏性变更 | 见决策点 D3/D2；如需保留将另行说明；本次 library 版本仍走 `library-6.x` 分支，随模块升级一起发布 |
| R8 | `RateLimit`/`Uuid`/`PubSub` 等模块 | 已 grep 确认不引用 Lock，无连带影响 | 无需额外回归 |

---

## 9. 需要你确认的决策点

| # | 决策 | 我的建议 | 备选 |
|---|---|---|---|
| D1 | `PHPRedisMutex` + `PredisMutex` 是否合并为单一 `RedisMutex` | **合并**（与上游同名同形，减少 2 个影子类；swoolefy 只有 2 处调用点需改） | 保留两个类名作为语义别名（各 6 行 ctor 转发），调用点少改 |
| D2 | `FlockMutex` / `MemcachedMutex` / `CASMutex` 三个空壳 | **删除**，README 直接指向 `Malkusch\Lock\Mutex\*`（空壳无附加值，且容器无 ext-memcached、无法测试） | 保留 `FlockMutex`（单机脚本常用） |
| D3 | `TransactionalMutex`（3.1.1 已移除该上游类） | **删除**（§4-① 实测无 PDO_MYSQL hook，事务型锁在协程 Worker 里风险大于收益；现网无调用点） | 自建 `extends AbstractMutex` 的等价实现（约 90 行 + 单测），保留「失败重放」能力 |
| D4 | 旧 `acquireLock()/releaseLock()` 非阻塞 API | **不提供**，用 §5.4-2 的短等待 `synchronized()` 范式与 `check()->then()` 替代（不做 Closure::bind 侵入上游私有键状态的 hack） | 用 `Closure::bind` 暴露 `tryLock()`（与上游 `DistributedMutex` 内部手法一致，但依赖私有属性名，小版本更新可能失效） |
| D5 | `MySQLMutex`/`PostgreSQLMutex` 是否随本次一起改造 | **一起改**（同一模块内保持一致的 3.1.1 契约，避免半升级状态） | 本次只交付 Redis 锁，DB 锁先删除并后续补 |
| D6 | 是否保留 2.x `SynchronizeTrait` 的协程 `synchronized()` 语义 | **保留**：在 `RedisMutex` 中基于 `lock()/unlock()` 重写（§5.6）。旧实现的超时重试会提前返回 `null`、且吞掉释放异常，重写版改为等待释放完成并按 3.1.1 契约抛异常 | 直接用上游 `synchronized()`，放弃子协程执行与 defer 释放 |

确认后我按 P0→P4 顺序实施，实施完在容器内跑单测 + swoolefy Test E2E，再更新 `src/Lock/README.md`；未经你允许不做 commit、不 push。

---

## 10. 工作量估算

| 项 | 规模 |
|---|---|
| `src/Lock` 重写（3 个类 + 删除 6–7 个文件） | 约 250 行新增 / 480 行删除 |
| `tests/Lock` 单测 | 约 500–700 行 |
| swoolefy 调用点 + E2E 验证入口 | 约 200 行 |
| README 重写 | 约 180 行 |
| 容器验证（单测 + Test 服务 E2E + 清理） | 一轮完整回归，预计 2 次迭代 |
