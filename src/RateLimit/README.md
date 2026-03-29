# RateLimit - 限流组件

基于 Redis 的分布式限流组件，提供两种限流算法实现。所有核心逻辑封装在 Lua 脚本中，保证原子性和分布式一致性。

同时兼容 **phpredis** 扩展和 **Predis** 库，自动识别驱动类型。

---

## 组件对比

| 特性 | DurationLimiter（滑动窗口） | TokenBucketLimiter（令牌桶） |
|------|---------------------------|----------------------------|
| 算法 | 滑动窗口计数 | 令牌桶 |
| Redis 数据结构 | Sorted Set | Hash |
| 突发流量 | 严格限制，窗口内不允许超额 | 允许 burst，桶满时可瞬间消耗所有令牌 |
| 限流粒度 | 每次请求消耗 1 个计数 | 支持单次消耗多个令牌 `isLimit(n)` |
| 流量特征 | 精确计数，均匀分配 | 平滑填充，允许瞬时峰值 |
| 时间精度 | 万分之一秒（10,000/s） | 微秒级（1,000,000/s） |
| 内存占用 | 随请求量增长（每请求一个 ZSET member） | 固定（仅 2 个 Hash field） |
| Key TTL | `windowSizeTime * 2` | `ceil(capacity / rate) * 2`，最小 60s |
| 适用场景 | API 调用次数限制、接口频率控制 | QPS 控制、流量整形、突发容忍 |

### 如何选择

- **严格限制次数**（如：每分钟最多 100 次请求）→ 使用 `DurationLimiter`
- **控制速率并容忍突发**（如：平均 QPS 10，允许瞬时 burst 到 50）→ 使用 `TokenBucketLimiter`

---

## DurationLimiter - 滑动窗口限流

### 原理

使用 Redis Sorted Set，以时间戳作为 score，每个请求作为一个 member。每次请求时：

1. 移除窗口外的过期数据（`ZREMRANGEBYSCORE`）
2. 统计窗口内请求数（`ZCARD`）
3. 未超限则添加新 member 并放行，否则拒绝

### 参数说明

| 参数 | 类型 | 说明 |
|------|------|------|
| `limitNum` | int | 窗口内允许的最大请求数 |
| `windowSizeTime` | int | 窗口大小（秒），不宜设置过大 |

### 使用示例

```php
use Common\Library\RateLimit\DurationLimiter;

$limiter = new DurationLimiter($redis);
$limiter->setRateKey('api:order:create');
$limiter->setLimitParams(100, 60); // 60 秒内最多 100 次请求

if ($limiter->isLimit()) {
    // 被限流
    throw new \Exception('Rate limit exceeded, please try again later');
}

// 查询当前窗口已使用的请求数
$currentCount = $limiter->getCurrentCount();
```

### 实现细节

- Lua 脚本内使用 `redis.call('TIME')` 获取服务器时间，保证分布式节点时间一致
- member 使用 `时间分数:计数` 格式（如 `17119200005678:42`），脚本内部生成，无需额外 Redis 调用
- 清理过期数据后使用 `ZCARD`（O(1)）替代 `ZCOUNT`（O(log N)）提高性能

---

## TokenBucketLimiter - 令牌桶限流

### 原理

使用 Redis Hash 存储桶状态（`tokens` 当前令牌数、`timestamp` 上次填充时间）。每次请求时：

1. 根据距上次的时间差，按速率计算新增令牌数
2. 新令牌数 = `min(capacity, 旧令牌 + 新增令牌)`
3. 如果令牌 >= 请求数，扣减令牌并放行；否则拒绝（不扣减）

### 参数说明

| 参数 | 类型 | 说明 |
|------|------|------|
| `capacity` | int | 桶容量，即最大令牌数（决定突发上限） |
| `rate` | float | 令牌填充速率（个/秒） |

### 使用示例

```php
use Common\Library\RateLimit\TokenBucketLimiter;

$limiter = new TokenBucketLimiter($redis);
$limiter->setRateKey('api:user:123');
$limiter->setLimitParams(50, 10.0); // 桶容量 50，每秒填充 10 个令牌

// 基本限流（消耗 1 个令牌）
if ($limiter->isLimit()) {
    throw new \Exception('Rate limit exceeded');
}

// 批量操作消耗多个令牌（如：批量导入消耗 5 个令牌）
if ($limiter->isLimit(5)) {
    throw new \Exception('Rate limit exceeded for batch operation');
}

// 查询当前剩余令牌数
$remaining = $limiter->getCurrentTokens();
```

### 实现细节

- 首次访问初始化为满桶（`tokens = capacity`），服务启动后可立即处理突发请求
- 被拒绝时仍更新填充状态（时间戳和已填充的令牌数），避免时间跳跃导致下次请求计算偏差
- 时间精度为微秒级（`秒 + 微秒 / 1,000,000`），填充计算精确
- TTL 动态计算：`ceil(capacity / rate) * 2`，最小 60 秒，确保空闲 key 及时回收
- Redis 仅存储 2 个 Hash field，内存占用固定，不随请求量增长
