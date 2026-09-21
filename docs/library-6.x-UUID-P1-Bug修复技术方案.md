# library-6.x UUID P1 Bug 修复技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块 | UUID（`UuidManager` / `UuidIncrement`） |
| 优先级 | P1 |
| 修复范围 | retry 状态、Channel 取号、`generateId()` 返回 `null` 后的运算 |
| 修复目标 | 修复已确认的 UUID 问题，并覆盖代码里所有同构调用点 |
| 原则 | 只修复 Bug，不新增功能，不改变正常成功路径，不修改公开 API |

对照文件：

| 文件 | 与本方案的关系 |
|---|---|
| `src/Uuid/UuidManager.php` | P1-1 / P1-2 / P1-3 主现场 |
| `src/Uuid/UuidIncrement.php` | 与 Manager **同一套** retry 污染、`null` 参与减法 |
| `src/Uuid/LuaScripts.php` | 不改 |
| `src/Uuid/README.md` | 实现时同步故障语义（不完整列表 / `null`） |
| `src/Exception/UuidException.php` | **不新抛**。现有失败路径是返回 `null` / 不完整数组 |

---

# 2. 问题总览

| 等级 | 模块 | 问题 | 代码结论 |
|---|---|---|---|
| P1 | UUID | `$retryTimes` 被 `--$this->retryTimes` 改成实例永久状态 | **确认 Bug**。一次失败会永久减少后续重试；耗尽后下一次失败会 `--0` 变成 `-1`，`while` 为真，**可能无限重试** |
| P1 | UUID | `getIncrIds()` Channel 循环先 `pop` 再判断数量 | **确认 Bug**。多消费 1 个 ID 并 `break` 丢弃 |
| P1 | UUID | `generateId()` 返回 `null` 后仍做 `$maxId - $n` | **确认 Bug**。PHP 8.4 会 `TypeError`。调用点不止 `getIncrIds()` |

原方案方向正确，但对现状描述不完整：

| 原方案 | 结论 |
|---|---|
| 只写 `UuidManager::$retryTimes` | **不完整**。`UuidIncrement` 第 128 行是同一写法 |
| 耗尽后下一次 retry=0、不再重试 | **不准确**。`do {} while` 至少执行一次，随后 `--0` 得到 `-1`，循环条件为真 |
| 只举 `getIncrIds` 的 `$maxId - $remainNum` | **不完整**。Tick 补货、`preBatchGenerateIds()` 同样减法 |
| Channel 只改内层 while | **不够**。外层 `length() > ($num + 1)` 会在池里明明够用时仍绕开 Channel 去打 Redis |
| 「按现有异常机制终止」 | **过空**。现有机制是返回 `null` / 不完整列表，**不是**抛 `UuidException` |

---

# 3. 总体修复原则

不进行：

- 不重新设计 UUID / Lua 算法，不改 ID 拼装规则。
- 不修改公开方法签名：`getOneId()` / `getIncrIds()` / `getIncrId()` / `preBatchGenerateIds()` / `registerTickPreBatchGenerateIds()`。
- 不新增 `setRetryTimes()` 等 API。
- 不改变 `static $poolIdsQueue` 的进程内共享模型。
- 不修改 Redis、PDO、Queue、Lock。
- 不把 `null` 转成 `0` 继续算。
- 不在失败路径新抛异常（会改变调用方契约）。README 已写明：全部失败时 `generateId` 返回 `null`，`getIncrIds` 允许不完整列表。

```text
修复 Bug
  ↓
保持现有架构与公开 API
  ↓
成功路径行为不变
  ↓
失败路径与 README 一致：null / 不完整数组，而不是 TypeError 或假 ID
```

---

# 4. P1-1：`$retryTimes` 实例状态污染

## 4.1 现状代码

两处完全同构：

```php
// UuidManager::generateId()
do {
    $dataArr = $this->doHandle($redis ?? $this->redis, $count);
    if (!empty($dataArr)) {
        break;
    }
    Coroutine::sleep($sleepTimeSecond);
    --$this->retryTimes;
} while ($this->retryTimes);

// UuidIncrement::generateId() 同样是 --$this->retryTimes
```

`$retryTimes` 既是默认配置，又被当成本次循环计数器。Swoolefy 组件容器会缓存同一个 `UuidManager` 实例，Tick 回调也复用 `$this`，因此污染会发生在生产路径上。

## 4.2 实际后果（比「下次 retry=0」更严重）

`do {} while ($this->retryTimes)` 至少执行一次；`--` 发生在失败之后。

| 场景 | 实例上的 `$this->retryTimes` | 下次请求 |
|---|---|---|
| 首次成功 | 仍为 3 | 正常 |
| 失败 1 次后成功 | **停留在 2** | 以后永远少 1 次重试 |
| 连续失败耗尽 | **停留在 0** | 见下表 |

耗尽后的下一次 `generateId()`：

```text
do { 第一次 EVAL }     ← 仍会执行
失败
--$this->retryTimes    ← 0 变成 -1
while (-1)             ← PHP 中非 0 为真
  → 无限 Coroutine::sleep / usleep + EVAL
```

所以 P1-1 不只是「后续没有重试」，还包括：

1. 一次短暂失败永久减少全局重试次数。
2. 重试耗尽后再失败，可能把 Worker 打进死循环。

`registerTickPreBatchGenerateIds()` 里每次 Tick 都调用 `$this->generateId()`。Tick 失败同样消耗并污染同一实例，进而拖累业务 `getIncrIds()`。

## 4.3 修复

保留属性当默认配置，**禁止**再写 `--$this->retryTimes`。

```php
$retryTimes = $this->retryTimes;
do {
    $dataArr = $this->doHandle(...);
    if (!empty($dataArr)) {
        break;
    }
    Coroutine::sleep($sleepTimeSecond); // Increment 侧仍用 usleep
    --$retryTimes;
} while ($retryTimes);
```

要求：

1. `UuidManager` 与 `UuidIncrement` 都改。
2. 每次 `generateId()` 从 `$this->retryTimes` 复制局部变量，只减局部变量。
3. 默认值保持 `3`，不改成功路径的 EVAL 次数（第一次 + 最多再失败重试到 3 次循环，与现在首次调用一致）。
4. 不新增 setter。

`while ($retryTimes)` 在局部变量减到 0 时结束；下次进入 `generateId()` 重新复制 3。不会出现 `-1`。

---

# 5. P1-2：`getIncrIds()` 多 pop 一个 UUID

## 5.1 内层循环（已确认）

```php
if (self::$poolIdsQueue->length() > ($num + 1)) {
    $popNum = 0;
    while ($uuid = self::$poolIdsQueue->pop(0.05)) {
        if ($popNum >= $num) {
            break;
        }
        $popNum++;
        $poolIds[] = $uuid;
    }
}
```

`while` 条件会先 `pop`。取满 `$num` 之后还会再 pop 一次，然后 `break`，该 ID 被丢弃且无法归还 Channel。

`getIncrIds(1)`、Channel `[A,B,C]`：返回 `[A]`，`B` 丢失，`C` 留在池中。

## 5.2 外层门槛（原方案未写，必须一并改）

```php
length() > ($num + 1)    // 即至少要有 num+2 个才会走池
```

| 池长度 | 请求 `$num` | 现状 |
|---|---|---|
| 2 | 1 | `2 > 2` 为假，**不走 Channel**，再 `generateId(1)`，池里 2 个 ID 闲置 |
| 100 | 99 | `100 > 100` 为假，绕开池去 Redis 再要 99 个 |

这不是新功能，是同一函数里让「优先从 Channel 取」失效的缺陷。只改内层 while、保留 `> ($num + 1)`，多数批量取号仍然打 Redis，P1-2 修不干净。

改为：池中数量 **不少于** 请求数量才出队。

```php
if (self::$poolIdsQueue->length() >= $num && $num > 0) {
```

`$num > 0` 避免 `getIncrIds(0)` 因 `length() >= 0` 误入分支（虽然内层 `popNum < 0` 不会 pop，但不应依赖）。

## 5.3 内层循环修复

先判断再 pop；超时用严格比较，避免把合法 ID `0` 当成失败（本模块 ID 实际是大整数，仍按生产写法处理）：

```php
$popNum = 0;
$poolIds = [];
while ($popNum < $num) {
    $uuid = self::$poolIdsQueue->pop(0.05);
    if ($uuid === false) {
        // Channel 空或超时，结束取池；不足部分走后面的 generateId
        break;
    }
    $popNum++;
    $poolIds[] = $uuid;
}
```

超时仍为 `0.05` 秒，禁止改成无限 `pop()`。

Tick 里主动清空积压的循环是故意排空，**不是**本 Bug，不要改成「少 pop 一个」。

```php
while (self::$poolIdsQueue->pop(0.02)) {
}
```

## 5.4 边界

| 输入 | 期望 |
|---|---|
| `$num <= 0` | 返回 `[]`，不 pop、不调用 `generateId` |
| 池刚好 `$num` 个 | 返回这 `$num` 个，不再 pop 下一个 |
| 池少于 `$num` | 能 pop 多少是多少，不足由 `generateId` 补；不得多 pop |
| `pop` 超时得到 `false` | 结束取池，走现有补号逻辑 |

`UuidIncrement` 用数组 `array_shift`，没有 Channel 多 pop 问题，P1-2 只改 `UuidManager::getIncrIds()`。

---

# 6. P1-3：`generateId()` 返回 `null` 后继续做减法

## 6.1 问题本质

`generateId()` **已经**在失败时 `return null`（主 Redis 重试尽 + followConnections 仍空）。Bug 在调用方把 `null` 当最大 ID：

```php
$minId = $maxId - $remainNum;   // PHP 8.4：TypeError
```

禁止：

```php
$maxId = $this->generateId() ?? 0;
$maxId = (int) $this->generateId();
```

都会把失败变成 `0`，算出负数或假号段。

## 6.2 必须加守卫的全部调用点

`generateId()` 自身成功路径不改。只在以下三处，拿到返回值后立刻判断：

### （1）`UuidManager::getIncrIds()`

```php
$maxId = $this->generateId($remainNum, $this->redis);
if ($maxId === null) {
    return $poolIds; // 可能是 [] 或部分池内 ID，与 README「不完整列表」一致
}
$minId = $maxId - $remainNum;
if ($minId > 0) {
    // ... 现有 for 循环
}
return $poolIds;
```

不要新抛异常。`errorReportClosure` 已在 `generateId()` 内部触发过。

### （2）`UuidManager::registerTickPreBatchGenerateIds()` 的 Tick 闭包

```php
$maxId = $this->generateId($poolSize);
if ($maxId === null) {
    return; // 本轮不 push；外层已有 try/catch，不要再制造 TypeError
}
$minId = $maxId - $poolSize;
```

### （3）`UuidIncrement::preBatchGenerateIds()`

```php
$maxId = $this->generateId($count);
if ($maxId === null) {
    return true; // 保持「总是返回 true」的现有签名语义，只是不往 poolIds 填假数据
}
$minId = $maxId - $count;
```

`UuidIncrement::getIncrId()` 在池空时 `return $this->generateId(1)`，已经把 `null` 交给调用方，**不必改**。

## 6.3 `getOneId()` 的连带行为（调用链，不是第四个功能）

```php
public function getOneId()
{
    $poolIds = $this->getIncrIds(1);
    return current($poolIds);
}
```

P1-3 修好后，失败时 `getIncrIds` 可能返回 `[]`。`current([])` 是 `false`，与 README「`getOneId` 返回 `null`」不一致。允许在 **不改方法签名** 的前提下写成：

```php
return $poolIds[0] ?? null;
```

这是 P1-3 的调用链收口，不新增 API。

---

# 7. 三个 Bug 修复后的流程

## 7.1 Retry

```text
$this->retryTimes = 3   （配置，只读）
        │
        ▼
generateId() 入口复制 $retryTimes
        │
        ├── EVAL 成功 → 返回 int（$this->retryTimes 仍为 3）
        └── 失败 → 只减局部变量，耗尽则走告警 / followConnections / return null
```

Tick 与业务请求互不削减对方的默认次数。

## 7.2 Channel 取号

```text
num <= 0 → []
length >= num → 循环 pop 恰好 num 个（超时则停）
不足 → generateId(差值)
         ├── int → 按现有 minId 公式补齐
         └── null → 返回已有部分，不做减法
```

## 7.3 `generateId()` 与调用方

```text
generateId
  ├── 有效 int → 调用方继续 $maxId - n
  └── null    → 调用方立即 return / return true / 跳过 push
                禁止进入减法
```

---

# 8. 代码修改范围

```text
src/Uuid/UuidManager.php     P1-1、P1-2、P1-3、getOneId 收口
src/Uuid/UuidIncrement.php   P1-1、P1-3（preBatchGenerateIds）
src/Uuid/README.md           实现时补一句：失败不再 TypeError；getOneId 失败为 null
```

不修改 `LuaScripts.php`、不修改其他模块。

---

# 9. 测试要求

Policy / 循环逻辑尽量可单测。Channel 用例需要 `ext-swoole`；无 Swoole 时跳过 Channel 用例，仍要覆盖 Increment 侧 retry 与 `null` 守卫。

## 9.1 retryTimes（Manager 与 Increment 都要）

1. 同一实例连续失败耗尽后，下一次 `generateId` 仍从 3 开始，且不会因 `-1` 死循环。
2. 失败 1 次再成功后，实例属性 `$retryTimes` 仍为 3（可用反射断言）。
3. Tick 或第二次 `getIncrIds` 不得继承上一次剩下的计数。

## 9.2 `getIncrIds()` Channel

| Case | 初始 Channel | 调用 | 期望返回 | 期望剩余 |
|---|---|---|---|---|
| 取 1 | `[A,B,C]` | `getIncrIds(1)` | `[A]` | `[B,C]` |
| 取 2 | `[A,B,C]` | `getIncrIds(2)` | `[A,B]` | `[C]` |
| 取满 | `[A,B,C]` | `getIncrIds(3)` | `[A,B,C]` | `[]` |
| 不足 | `[A,B]` | `getIncrIds(3)` | 先 A、B，不足再 generate；**不得**在只有 A、B 时多 pop | 视补号结果 |
| 0 | 任意 | `getIncrIds(0)` | `[]` | 不变 |
| 负数 | 任意 | `getIncrIds(-1)` | `[]` | 不变 |

原门槛 `length > num+1` 修复后：`[A,B]` 上 `getIncrIds(1)` 必须从池取 `A`，而不是忽略池去 Redis。

## 9.3 `generateId() === null`

用可注入的 Redis 桩让 `doHandle` 一直返回空：

- `getIncrIds`：不出现 TypeError，返回已有元素或 `[]`
- Tick 闭包：不 push 假 ID
- `preBatchGenerateIds`：`poolIds` 不增长，返回 `true`
- 禁止 `?? 0` / `(int) null` 后仍算出号段

## 9.4 回归

- 成功时 ID 仍为 `prefixNumber + incrId` 规则，单号 / 批量数量与请求一致。
- 协程并发（有 Swoole 时）：不因多 pop 丢号；不出现把 `null` 当 ID 的 TypeError。
- 公开方法签名不变。

---

# 10. Code Review Checklist

## P1-1

- [ ] `UuidManager` 与 `UuidIncrement` 都不再 `--$this->retryTimes`
- [ ] 每次 `generateId()` 使用局部 `$retryTimes`
- [ ] 失败 1 次成功后，属性仍为默认 3
- [ ] 耗尽后再调用不会 `while (-1)` 死循环
- [ ] 默认次数仍为 3

## P1-2

- [ ] `pop` 前判断 `$popNum < $num`
- [ ] 超时判断 `$uuid === false`
- [ ] 外层改为 `length() >= $num && $num > 0`
- [ ] `$num <= 0` 不消费 Channel、不 `generateId`
- [ ] Tick 清空积压的 while 未误改
- [ ] 未改 `UuidIncrement` 的数组池 shift 逻辑

## P1-3

- [ ] `getIncrIds` / Tick / `preBatchGenerateIds` 三处 `=== null` 后不再减法
- [ ] 不用 `?? 0`、`(int)`
- [ ] 失败不抛新异常；`getIncrIds` 返回已收集列表
- [ ] `getOneId` 空列表返回 `null` 而非 `false`
- [ ] `getIncrId()` 继续原样返回 `generateId` 的 `null`
- [ ] 成功路径公式不变

---

# 11. 最终验收

```text
① retryTimes 只作默认配置
   局部计数，失败不污染实例，不会减到负数死循环

② getIncrIds
   length >= num 才出队；先判断再 pop；不多丢 1 个 ID

③ generateId() === null
   所有 $maxId - n 调用点立即停
   与 README 一致：null / 不完整列表，而不是 TypeError 或 0 号段
```

> **只修已确认问题及其同构调用点。不改算法、不改公开 API、失败语义与现有 README 对齐。**
