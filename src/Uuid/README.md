# Uuid

基于 Redis 的**分布式自增 ID**生成器（非 RFC UUID 字符串）。通过 Lua 原子脚本将「时间前缀 + 段内序号」拼成大整数 ID，支持 phpredis / Predis，以及主从 Redis 降级。

命名空间：`Swoolefy\Library\Uuid`

---

## 目录结构

```
Uuid/
├── UuidManager.php      # 协程友好：Channel 预分配 + Tick 批量补货（推荐）
├── UuidIncrement.php    # 简单版：进程内数组池 + 按需/预批量生成
├── LuaScripts.php       # Redis Lua：INCRBY + 时间戳前缀
└── README.md
```

---

## ID 生成原理

Lua（`LuaScripts::getUuidLuaScript()`）在单次 `EVAL` 内完成：

1. `INCRBY incrKey step`，段内序号超过 `999990` 时删 key 重新自增（滚动段）
2. 用 Redis `TIME` 取秒 + 微秒前 3 位，组成毫秒级时间串
3. 新段首写时设置 `EXPIRE`（默认 TTL 约 15s），并与上一毫秒戳比对，同一毫秒冲突则返回空（由 PHP 侧重试）
4. 返回 `{ prefixNumber, incrId }`，其中 `prefixNumber = second + msecond + '000000'`

PHP 侧最终 ID：

```text
autoIncrId = (int) prefixNumber + (int) incrId
```

特点：趋势递增、跨节点靠 Redis 保证唯一、毫秒级冲突自动重试。

---

## 两个类怎么选

| | `UuidManager` | `UuidIncrement` |
|--|---------------|-----------------|
| 预分配池 | `Swoole\Coroutine\Channel` | PHP 数组 `$poolIds` |
| 定时补货 | `registerTickPreBatchGenerateIds`（`goTick`） | 手动 `preBatchGenerateIds` |
| 失败重试 | `Coroutine::sleep(0.15)` × 3 | `usleep(15ms)` × 3 |
| 适用场景 | Swoole 协程 / Worker（推荐） | 脚本、非协程、简单批量 |

二者共用同一套 Lua 与「主 Redis 失败 → 回调告警 → followConnections」逻辑。

---

## UuidManager（推荐）

### 构造

```php
use Swoolefy\Library\Uuid\UuidManager;

$manager = new UuidManager(
    $redis,                    // RedisConnection（phpredis 或 Predis）
    'uuid-key',                // Redis 自增 key
    15,                        // incrKey TTL（秒，建议 5~20）
    [$slaveRedis],             // 可选：主失败时的备用连接
    function () {              // 可选：主 Redis 连续失败时的告警闭包
        // log / metrics
    }
);

// 或
$manager = UuidManager::getInstance($redis, 'uuid-key', 15);
```

### 取号

```php
// 单个
$id = $manager->getOneId();

// 批量（优先从 Channel 池取，不足再向 Redis 要一段）
$ids = $manager->getIncrIds(10);
```

### 定时预热（降低 Redis QPS）

```php
// $timeMs：补货周期，钳制在 1000~5000ms，建议 1000~2000
// $poolSize：每次预生成数量，建议 100~1000
$manager->registerTickPreBatchGenerateIds(1000, 200);
```

同一进程内 Channel 为静态共享；周期内若积压过久会清空旧池再补货。

### swoolefy 组件示例

```php
// Config/component/common.php
'uuid' => function () {
    $redis = Application::getApp()->get('redis')->getObject();
    return UuidManager::getInstance($redis, 'uuid-key');
},
```

```php
$ids = App::getUUid()->getIncrIds(10);
```

试调：`GET http://127.0.0.1:9501/api/getUuid`（`UuidController`）。

---

## UuidIncrement（简单版）

```php
use Swoolefy\Library\Uuid\UuidIncrement;

$uuid = new UuidIncrement($redis, 'order_incr_id', 15);

// 预批量（上限 20000，≤0 时按 10）
$uuid->preBatchGenerateIds(100);

// 逐个取出；池空则实时 generateId(1)
$id = $uuid->getIncrId();
```

单元测试参考：`library/tests/Uuid/UuidTest.php`。

---

## 故障与降级

1. 主 Redis `EVAL` 失败或返回空 → 短暂休眠后最多重试 **3** 次  
2. 仍失败 → 执行 `$errorReportClosure`（若有）  
3. 再遍历 `$followConnections` 备用连接  
4. 全部失败 → `getOneId` / `generateId` 返回 `null`，`getIncrIds` 可能得到不完整列表  

请保证 Redis 高可用，或配置只读从库以外的可写备用实例。

---

## Redis Key

| Key | 说明 |
|-----|------|
| `{incrKey}` | 段内自增计数，带 TTL |
| `{incrKey}_time_stamp` | 上一成功发放的毫秒时间戳，用于同毫秒冲突检测 |

---

## 注意点

1. **不是 UUID v4**：返回的是大整数（数字字符串），适合订单号、业务主键等。  
2. **时钟依赖 Redis `TIME`**：以 Redis 服务器时间为准，与 PHP 本机时钟解耦。  
3. **协程环境优先 `UuidManager`**：`UuidIncrement` 的 `usleep` 会阻塞 Worker。  
4. **`getInstance` 每次 `new`**：名称虽像单例，实际每次构造新实例；组件容器应自行缓存。  
5. **Channel 静态池**：多 `UuidManager` 实例共享同一 `poolIdsQueue`，同进程请统一 `incrKey` 策略，避免混用不同业务 key 却共用池。  
6. **驱动**：自动识别 Predis，`eval` 参数顺序与 phpredis 不同，已在内部处理。

---

## 快速对照

```php
// 协程：组件取号
App::getUUid()->getOneId();
App::getUUid()->getIncrIds(5);

// 脚本：简单自增
$u = new UuidIncrement($redis, 'biz_id');
$u->preBatchGenerateIds(50);
while ($need--) {
    echo $u->getIncrId(), "\n";
}
```
