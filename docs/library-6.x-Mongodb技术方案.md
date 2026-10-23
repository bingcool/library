# Library 6.x Mongodb 模块重构技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块目录 | `src/Mongodb/` |
| 命名空间 | `Swoolefy\Library\Mongodb` |
| 文档状态 | 已实施（2026-10-23） |
| 日期 | 2026-10-23 |
| 依赖 | `mongodb/mongodb` `v2.3.x-dev`（`composer.json` 已安装）、`ext-mongodb` `^2.3` |
| 定位 | 对 `MongoDB\Client` / `MongoDB\Collection` 的轻量、显式封装：统一数组解码、`_id` 归一化、超时配置与错误契约 |
| 非定位 | 不是 ODM，不提供 Schema、关联、查询构造器、迁移工具 |

> 关联文档：暂无。旧实现见 [MongodbModel.php](../src/Mongodb/MongodbModel.php)、[MongodbCollection.php](../src/Mongodb/MongodbCollection.php)。

---

## 2. 背景与目标

### 2.1 现状

`src/Mongodb` 现有两个类：

- `MongodbModel`：连接入口，负责创建 `MongoDB\Client`、选择数据库、缓存 collection。
- `MongodbCollection`：对 `MongoDB\Collection` 的包装，提供 `find/findOne/insert/update/delete` 等方法，并把 `_id` 转成字符串或指定字段。

这两个类是在 MongoDB PHP Library 1.x 时代写的，当前依赖已升级到 2.3.x-dev，原有实现存在一批确定性缺陷（见第 3 节），且没有测试、README 和类型契约。

### 2.2 目标

1. 以 `mongodb/mongodb` v2.3.x-dev 的真实 API 为准，重写 `src/Mongodb`。
2. 默认返回 PHP 数组，屏蔽 `BSONDocument` / `BSONArray`，避免业务层混用两种文档形态。
3. 统一 `_id` 语义：读出的 `ObjectId` 转字符串；过滤条件中的 24 位十六进制字符串自动转 `ObjectId`；其他类型不误转。
4. 写操作返回驱动原生结果对象（`InsertOneResult`、`UpdateResult` 等），保留 matched / modified / upserted / deleted 的真实语义。
5. 配置 fail-fast：URI、数据库名、未知配置键、`typeMap` / `codec` 冲突在构造阶段报错。
6. 为同步阻塞行为提供明确边界：默认连接/选择/读写超时，文档写清协程限制。
7. 补齐单元测试、真实 MongoDB 集成测试、swoolefy Test 服务端到端验证、模块 README。

### 2.3 非目标（v1 明确不做）

- 不做 ODM：不解析类属性、不做关系、不生成 Schema。
- 不做查询 DSL：过滤条件、更新文档、聚合管道直接使用 MongoDB 原生数组语法。
- 不封装事务、Change Stream、GridFS、索引管理、CSFLE；通过 `raw()` 或 `database()` 使用原生 API。
- 不实现重试。驱动自身的 retryable reads/writes 已经存在；库层再加盲重试可能重复写入。
- 不启用 `SWOOLE_HOOK_MONGODB`，不宣称 MongoDB 调用被协程 hook（原因见 4.3）。
- 不保留旧 API 别名。旧调用方按第 12 节迁移。

---

## 3. 现状缺陷

| 编号 | 位置 | 问题 | 影响 |
|---|---|---|---|
| M-01 | [MongodbModel.php:40](../src/Mongodb/MongodbModel.php#L40)、[:70](../src/Mongodb/MongodbModel.php#L70) | 默认 URI 写成 `mongodb=127.0.0.1:27017`，协议分隔符错误 | 不传 URI 时连接直接失败 |
| M-02 | [MongodbCollection.php:247](../src/Mongodb/MongodbCollection.php#L247) | `insertMany()` 委托给 `insert()`；`insert()` 在单条时把整个 `$documents` 列表传给 `insertOne()` | 单条批量插入变成嵌套数组文档 |
| M-03 | [MongodbCollection.php:339](../src/Mongodb/MongodbCollection.php#L339)、[:373](../src/Mongodb/MongodbCollection.php#L373) | `update*()` 用 `getUpsertedCount()` 判断成功 | 正常 match/modify 但未 upsert 时返回 `false`，误判失败 |
| M-04 | [MongodbCollection.php:108](../src/Mongodb/MongodbCollection.php#L108) | `findOne()` 未命中时 `$document` 未定义 | 产生 warning / 返回未定义变量 |
| M-05 | [MongodbCollection.php:384](../src/Mongodb/MongodbCollection.php#L384) | 调用 v2 已移除的 `count()` | 运行时报错，应使用 `countDocuments()` |
| M-06 | [MongodbCollection.php:52](../src/Mongodb/MongodbCollection.php#L52) | 任意字符串 `_id` 都 `new ObjectId()` | 字符串主键、非法 24 位字符串直接抛异常；`$in/$ne` 等操作符未处理 |
| M-07 | 读结果处理 | 所有 `_id` 一律 `(string)`，包括 int、Binary、复合 `_id` | 主键类型被破坏，复合 `_id` 不可用 |
| M-08 | [MongodbCollection.php:407](../src/Mongodb/MongodbCollection.php#L407) | `__call()` 直通原生 collection | 绕过 `_id` 归一化、`typeMap` 与选项校验，行为不一致 |
| M-09 | 全局 | 未设置 `typeMap`，默认返回 `BSONDocument` / `BSONArray` | 业务层拿到对象而非数组，JSON 序列化与数组函数不可用 |
| M-10 | 全局 | 未设置连接/选择/读写超时 | 阻塞 Worker 的时间不可控 |
| M-11 | [MongodbModel.php:97](../src/Mongodb/MongodbModel.php#L97) | `dbInstance()` 缓存第一次的数据库对象，后续 `setDatabase()` 不生效 | 切换数据库无效 |
| M-12 | 全局 | 无字符串类型、无返回类型、无测试、无 README；`tests/MongoDb/MongoDb.php` 是临时脚本 | 无法回归，行为不可验证 |

---

## 4. 依赖与环境实测

### 4.1 版本矩阵

| 环境 | PHP | ext-mongodb | `mongodb/mongodb` | 结论 |
|---|---|---|---|---|
| 容器 `swoolefy-php85-v62` | 8.5.8 | 2.3.3 | v2.3.x-dev | 可运行集成测试 |
| 宿主机 | 8.4.14 | 1.19.4 | v2.3.x-dev（vendor 已存在） | 不能跑真实连接；纯配置/归一化单测可跑 |
| MongoDB 容器 | `mongo:6.0` | - | - | 现有 `mongodb` 容器数据卷与运行凭据不一致；测试改用临时无认证容器 |

说明：

- `mongodb/mongodb` v2.3 要求 `ext-mongodb ^2.3`，宿主机的 1.19.4 不满足，因此真实 MongoDB 测试必须在容器内执行。
- 现有 `mongodb` 容器按认证模式启动，但数据卷中保存的 root 凭据与容器环境变量不一致（SCRAM storedKey mismatch），现有账号无法通过认证。未改动该容器数据卷与凭据；集成测试通过 `MONGO_URI` 指向临时无认证 `mongo:6.0` 容器，测试完成后清理。
- 集成测试在没有可用 `MONGO_URI` 时跳过，不依赖任何固定环境凭据。

### 4.2 MongoDB PHP Library v2 API 实测结论

以下行为在容器内对临时 MongoDB 6.0 实测确认，直接决定封装方式：

| 行为 | 实测结论 | 设计影响 |
|---|---|---|
| `Client` 构造 | `Client(?string $uri, array $uriOptions = [], array $driverOptions = [])`；`driverOptions` 支持 `typeMap` | 在 Client 层统一设置数组 `typeMap` |
| 默认解码 | 不设置 `typeMap` 时 `findOne()` 返回 `MongoDB\Model\BSONDocument`，嵌套数组为 `BSONArray` | 必须强制数组解码 |
| 每个调用的 `typeMap` | `findOne(..., ['typeMap' => [...]])` 可覆盖 | 包装器每个读方法都要覆盖，防止调用方穿透 |
| `returnDocument` | 只接受整数常量 `FindOneAndUpdate::RETURN_DOCUMENT_BEFORE`(1) / `AFTER`(2)；传字符串 `'after'` 抛 `MongoDB\Exception\InvalidArgumentException` | 包装器校验整数并给出明确错误 |
| `findOneAndUpdate` 默认返回 | 驱动默认 `BEFORE` | 包装器不改变默认值，文档要求显式传常量 |
| `includeResultMetadata` | v2.3.x-dev 已移除该选项与 `ModifyResult`；`findOneAnd*` 直接返回文档或 `null` | 包装器拒绝该选项，避免调用方误以为能拿到 metadata |
| `findOneAnd*` 未命中 | 返回 `null` | 包装器返回 `?array` |
| `UpdateResult` | `getMatchedCount()` / `getModifiedCount()` / `getUpsertedCount()` 均为真实计数 | 写操作返回原生结果对象 |
| `DeleteResult` | `getDeletedCount()`，0 是合法结果 | 不再用 `false` 表示 0 |
| 插入结果 | `InsertOneResult::getInsertedId()` / `InsertManyResult::getInsertedIds()` | 调用方可直接拿主键 |
| 计数 | v2 使用 `countDocuments()` / `estimatedDocumentCount()`；`count()` 不存在 | 删除旧 `count()` |
| `distinct` | 返回原生值数组；`_id` 上的 `ObjectId` 不自动转字符串 | v1 保持原生语义，文档说明 |
| `aggregate` | 返回 `CursorInterface`；结果文档可为任意结构，`_id` 可能来自 `$group` | 包装器转数组，但**不**做 `_id` 归一化，避免破坏分组结果 |
| `bulkWrite` | 支持 `insertOne/updateOne/updateMany/replaceOne/deleteOne/deleteMany`，返回 `BulkWriteResult` | 包装器对已知操作归一化过滤条件/插入文档 |
| `Client::__debugInfo()` | 会输出完整 URI | 本模块的配置与模型必须自行脱敏 |
| 连接超时 | 不可达地址在设置 `serverSelectionTimeoutMS` / `connectTimeoutMS` 后按配置抛 `ConnectionTimeoutException` | 默认设置超时，避免无限阻塞 |

### 4.3 Swoole 协程 hook 实测结论

容器内 Swoole 6.2.2：

```text
SWOOLE_HOOK_MONGODB = 4194304 (0x400000)
SWOOLE_HOOK_ALL     = 2143287295 (0x7FBFF7FF)
(SWOOLE_HOOK_ALL & SWOOLE_HOOK_MONGODB) = 0
SWOOLE_HOOK_ALL | SWOOLE_HOOK_MONGODB = 2147481599 (0x7FFFF7FF)
```

结论：

1. `SWOOLE_HOOK_ALL` **不包含** `SWOOLE_HOOK_MONGODB`。
2. 即使单独打开 `SWOOLE_HOOK_MONGODB`，Swoole 走的是 `Swoole\MongoDB\Client` + `Swoole\RemoteObject` 代理，不是透明的 socket hook。当前容器内该路径会尝试远端安装/加载真实 `MongoDB\Client`，出现扩展版本不匹配与类加载失败。
3. 官方 `MongoDB\Client` 在协程中执行时仍然是同步阻塞调用，会阻塞当前 Worker 进程，而不是只挂起当前协程。

因此 v1 的明确边界是：

- 使用官方 `MongoDB\Client`，不启用 `SWOOLE_HOOK_MONGODB`。
- 通过默认超时限制最坏阻塞时间。
- 文档明确：高并发、长查询、聚合等场景应放到 Task Worker、队列或独立进程；不要在 HTTP Worker 中执行不可控的长 Mongo 操作。
- 如果未来 Swoole 的 MongoDB RemoteObject 方案稳定，可在保持本模块 API 不变的前提下增加异步客户端实现。

---

## 5. 设计原则

1. **显式优于魔法**：不做 `__call` 直通；常用操作显式封装，高级用法走 `raw()`。
2. **数组进、数组出**：所有读操作强制 `root/document/array` 都是 PHP 数组。
3. **主键归一化集中管理**：`_id` 的读写转换只放在 `IdNormalizer`，不散落在每个方法。
4. **写操作不丢语义**：返回驱动原生结果对象，不用 `int|false` 猜测成功与否。
5. **配置 fail-fast**：缺 URI、缺数据库、未知键、类型错误、`typeMap`/`codec` 冲突在构造时报错。
6. **不重试**：驱动层已有 retryable reads/writes；库层不增加盲重试，避免重复写入。
7. **不包装驱动异常**：连接、认证、重复键、命令错误保留 `MongoDB\Driver\Exception\*` 类型，调用方可精确捕获。
8. **不泄露凭据**：异常信息、`__debugInfo()`、README 示例都不包含真实 URI/密码。
9. **按协程使用实例**：`MongodbModel` 持有 Client 与缓存，不跨协程共享；swoolefy 组件 `get()` 每协程一个实例。

---

## 6. 目录与类结构

```text
src/Mongodb/
├── Config/
│   └── MongodbConfig.php      # 配置解析与校验（final）
├── Support/
│   └── IdNormalizer.php       # _id / ObjectId 归一化（final，无连接依赖）
├── MongodbCollection.php      # Collection 包装（final）
├── MongodbModel.php           # 连接入口 / 可继承的模型基类
└── README.md                  # 使用文档（实施阶段新增）
```

| 类 | 职责 | 是否可继承 |
|---|---|---|
| `MongodbConfig` | 解析 `uri/database/uri_options/driver_options/id_key/object_id`；校验、默认超时、脱敏 | 否 |
| `IdNormalizer` | 过滤条件、插入文档、读结果中的 `_id` 转换；别名映射；碰撞保护 | 否 |
| `MongodbCollection` | 常用 CRUD/原子操作/计数/聚合；强制数组解码；返回原生写结果 | 否 |
| `MongodbModel` | 懒创建 Client；数据库/collection 缓存；`ping()`；`client()`/`database()`/`collection()` 逃生口 | 是（业务模型可继承） |

---

## 7. 配置设计

### 7.1 `MongodbConfig` 配置项

```php
new MongodbModel([
    'uri' => getenv('MONGO_URI'),          // 必填
    'database' => getenv('MONGO_DB'),      // 必填
    'uri_options' => [                     // 可选
        'serverSelectionTimeoutMS' => 3000,
        'connectTimeoutMS' => 2000,
        'socketTimeoutMS' => 30000,
    ],
    'driver_options' => [],                // 可选；typeMap 由库管理
    'id_key' => '_id',                     // 可选；默认 _id
    'object_id' => true,                   // 可选；默认 true
]);
```

| 键 | 类型 | 必填 | 默认 | 说明 |
|---|---|---|---|---|
| `uri` | string | 是 | 无 | 必须以 `mongodb://` 或 `mongodb+srv://` 开头；不提供 localhost 默认值 |
| `database` | string | 是 | 无 | 默认数据库；非空、长度 ≤ 64 字节、不含 MongoDB 非法字符 |
| `uri_options` | array | 否 | 见 7.3 | 直接传给 `MongoDB\Client` |
| `driver_options` | array | 否 | `[]` | 直接传给 `MongoDB\Client`；`typeMap` / `codec` 由库接管 |
| `id_key` | string | 否 | `_id` | 读结果中的主键字段名；也是过滤条件的别名 |
| `object_id` | bool | 否 | `true` | 是否在过滤/插入时把 24 位十六进制字符串转 `ObjectId`，读结果是否把 `ObjectId` 转字符串 |

### 7.2 校验规则

构造阶段直接抛 `InvalidArgumentException`：

- 未知配置键：列出未知键名，不输出配置值。
- `uri` 缺失、为空、协议不是 `mongodb://` 或 `mongodb+srv://`。
- `database` 缺失、为空、超长或含非法字符。
- `uri_options` / `driver_options` 不是数组。
- `driver_options` 含 `typeMap` 或 `codec`：库固定数组解码，不能由调用方覆盖；需要自定义解码请使用 `raw()`。
- `id_key` 为空、含 `.`、以 `$` 开头或含 null 字节。
- `object_id` 不是 bool。

错误信息中**不包含完整 URI**，避免把账号密码写进日志。

### 7.3 默认超时

在用户未显式覆盖时，`uri_options` 合并以下默认值：

| 选项 | 默认值 | 原因 |
|---|---|---|
| `serverSelectionTimeoutMS` | 3000 | 驱动默认 30s，对 Worker 太长 |
| `connectTimeoutMS` | 2000 | 驱动默认 10s |
| `socketTimeoutMS` | 30000 | 驱动默认 0（无限等待） |

合并方式为“用户值优先”：

```php
$uriOptions = $userUriOptions + self::DEFAULT_URI_OPTIONS;
```

注意：

- `socketTimeoutMS` 限制的是 socket 读写，不是服务端查询总时长；长查询还需要在操作选项里传 `maxTimeMS`。
- `retryWrites` / `retryReads` 保持驱动默认；需要关闭时在 `uri_options` 显式设置。
- 连接数、连接池由 ext-mongodb 内部管理；本模块不额外做连接池。

### 7.4 凭据与脱敏

- URI、`uri_options` 可能包含密码、TLS 私钥口令、认证机制属性，不在异常、日志、`__debugInfo()` 中输出。
- `MongodbConfig::__debugInfo()` 只输出：数据库名、`id_key`、`object_id`、`uri_options` 的键名、`driver_options` 的键名；URI 显示为 `[redacted]`。
- `MongodbModel::__debugInfo()` 不输出 Client，只输出数据库名、是否已建立连接等无敏感信息字段。
- README 示例统一使用 `getenv('MONGO_URI')`，不出现真实账号密码。

---

## 8. API 设计

### 8.1 `MongodbModel`

```php
class MongodbModel
{
    public function __construct(array|MongodbConfig $config);

    public function client(): \MongoDB\Client;

    public function database(?string $name = null): \MongoDB\Database;

    public function collection(
        string $name,
        ?string $database = null
    ): MongodbCollection;

    public function ping(): bool;

    public function __get(string $name): MongodbCollection;

    public function __debugInfo(): array;
}
```

行为约定：

- 构造只做配置校验，**不建立连接**；首次 `client()` / `database()` / `collection()` 时才创建 `MongoDB\Client`。
- `database(null)` 使用配置的默认数据库；`database('other')` 可用于跨库访问，按库名缓存。
- `collection($name, null)` 使用默认库；`collection($name, 'other')` 使用指定库。缓存键为 `数据库名 + "\0" + collection 名`，避免同名 collection 跨库串用。
- `__get($name)` 等价于 `collection($name)`，仅提供便捷写法。
- `ping()` 在默认数据库执行 `['ping' => 1]`，`ok == 1` 返回 `true`；连接/认证/命令异常原样抛出。
- `__destruct()` 清空 Client、Database、Collection 缓存；连接由 ext-mongodb 在对象释放时关闭。
- `MongodbModel` 不做连接重试、不做断线重连；由驱动和调用方决定。
- 该类可被业务继承，用于封装领域方法；内部缓存不跨协程共享。

跨库 collection 示例：

```php
$users = $model->collection('users');              // 默认库
$logs  = $model->collection('logs', 'archive');    // archive 库
```

### 8.2 `MongodbCollection`

```php
final class MongodbCollection
{
    public function __construct(
        \MongoDB\Collection $collection,
        string $idKey = '_id',
        bool $objectId = true
    );

    public function raw(): \MongoDB\Collection;
    public function getName(): string;
    public function getNamespace(): string;

    // 读
    public function find(array $filter = [], array $options = []): array;
    public function iterate(array $filter = [], array $options = []): \Generator;
    public function findOne(array $filter = [], array $options = []): ?array;
    public function countDocuments(array $filter = [], array $options = []): int;
    public function estimatedDocumentCount(array $options = []): int;
    public function distinct(string $fieldName, array $filter = [], array $options = []): array;
    public function aggregate(array $pipeline, array $options = []): array;

    // 原子读写
    public function findOneAndDelete(array $filter, array $options = []): ?array;
    public function findOneAndReplace(array $filter, array $replacement, array $options = []): ?array;
    public function findOneAndUpdate(array $filter, array $update, array $options = []): ?array;

    // 写
    public function insertOne(array $document, array $options = []): \MongoDB\InsertOneResult;
    public function insertMany(array $documents, array $options = []): \MongoDB\InsertManyResult;
    public function updateOne(array $filter, array $update, array $options = []): \MongoDB\UpdateResult;
    public function updateMany(array $filter, array $update, array $options = []): \MongoDB\UpdateResult;
    public function replaceOne(array $filter, array $replacement, array $options = []): \MongoDB\UpdateResult;
    public function deleteOne(array $filter, array $options = []): \MongoDB\DeleteResult;
    public function deleteMany(array $filter, array $options = []): \MongoDB\DeleteResult;
    public function bulkWrite(array $operations, array $options = []): \MongoDB\BulkWriteResult;
}
```

说明：

- `find()` 返回数组列表，语义与旧实现一致，但底层通过 `iterate()` 逐条归一化；数据量大时用 `iterate()` 或传 `limit`，避免一次性占用内存。
- `iterate()` 返回生成器；提前 `break` 时生成器销毁，cursor 随引用释放。
- `findOne()` 未命中返回 `null`，不产生未定义变量。
- `aggregate()` 返回数组列表，但**不做 `_id` 归一化**：聚合结果的 `_id` 可能是分组键，统一改写会破坏业务语义。需要原生 cursor 或自定义解码时用 `raw()->aggregate()`。
- `distinct()` 返回原生值数组，`ObjectId` 不转字符串；与 `find()` 的差异在 README 中明确。
- 所有写方法返回驱动原生结果对象，调用方自行读取 `getInsertedId()`、`getMatchedCount()`、`getModifiedCount()`、`getDeletedCount()` 等。
- 不提供 `insert()`、`update()`、`delete()`、`count()` 等旧别名；迁移见第 12 节。

### 8.3 `_id` / `ObjectId` 语义

`object_id = true`（默认）时：

| 场景 | 输入 | 输出/行为 |
|---|---|---|
| 过滤条件 `_id` | 24 位十六进制字符串 | 转 `MongoDB\BSON\ObjectId` |
| 过滤条件 `_id` | `ObjectId` | 原样 |
| 过滤条件 `_id` | 其他字符串 | 原样，支持字符串主键 |
| 过滤条件 `_id` | `['$in' => [...]]` 等操作符 | 递归转换受支持操作符内的 24 位字符串 |
| 插入文档 `_id` | 24 位十六进制字符串 | 复制文档后转 `ObjectId`，不修改调用方数组 |
| 插入文档 `_id` | 其他值 | 原样 |
| 读结果 `_id` | `ObjectId` | 转字符串 |
| 读结果 `_id` | int / string / 复合数组 | 原样，不强制转字符串 |
| 嵌套字段中的 `ObjectId` | 任意 | 原样，不递归改写 |

支持的过滤操作符：顶层 `$and`、`$or`、`$nor` 会递归处理各分支；字段级支持 `$eq`、`$ne`、`$in`、`$nin`、`$all`；其他操作符（`$exists`、`$type`、`$not` 等）保持原值。复合 `_id` 文档不递归改写。不会进入普通子文档内部改写同名 `_id`，避免误改嵌套数据结构。

`object_id = false` 时：

- 不做任何字符串 ↔ `ObjectId` 转换。
- 集合使用 ObjectId 主键时，过滤条件需由调用方传 `ObjectId`。
- 读结果保留 `ObjectId` 对象。
- 适用于字符串主键、UUID、复合主键，或调用方需要原生 BSON 类型的场景。

`id_key` 别名：

- 默认 `_id`，此时不重命名。
- 配置为 `id` 时：
  - 读结果：把 `_id` 的值放到 `id` 并删除 `_id`；如果文档本身已有非空 `id` 字段，则不覆盖业务字段，保留 `_id`，避免数据丢失。
  - 过滤条件：`id` 映射为 `_id`；同时出现 `_id` 与 `id` 时以 `_id` 为准，忽略 `id`。
  - 插入文档：只有 `id` 时映射为 `_id` 并删除 `id`；同时出现 `_id` 与 `id` 时抛 `InvalidArgumentException`，避免歧义。
  - `id_key` 是保留别名，业务文档不应再使用同名字段；README 会明确提示。

### 8.4 `typeMap` 与选项

- 在 `MongoDB\Client` 的 `driver_options` 中设置：

  ```php
  ['root' => 'array', 'document' => 'array', 'array' => 'array']
  ```

- `MongodbCollection` 的每个读方法再强制写一次 `typeMap`，避免调用方通过 `$options` 穿透。
- 读方法收到 `typeMap` 或 `codec` 选项时抛 `InvalidArgumentException`，提示使用 `raw()`。
- 写方法不强制 `typeMap`；写结果对象与解码无关。
- 所有操作选项（`projection`、`sort`、`limit`、`skip`、`hint`、`collation`、`session`、`maxTimeMS` 等）原样透传。

### 8.5 `findOneAnd*` 选项

- `findOneAndUpdate` / `findOneAndReplace`：
  - `returnDocument` 默认保持驱动默认 `RETURN_DOCUMENT_BEFORE`；需要更新后的文档必须显式传 `RETURN_DOCUMENT_AFTER`。
  - 只接受整数常量 1 / 2；字符串或其他整数抛 `InvalidArgumentException`。
  - 拒绝 `includeResultMetadata`：v2.3.x-dev 已移除该能力，传了也不会返回 metadata。
- `findOneAndDelete`：
  - 不接受 `returnDocument`；传了抛 `InvalidArgumentException`。
  - 未命中返回 `null`。
- 三者返回的文档都经过 `IdNormalizer::normalizeDocument()`。

示例：

```php
use MongoDB\Operation\FindOneAndUpdate;

$doc = $users->findOneAndUpdate(
    ['_id' => $id],
    ['$inc' => ['login_count' => 1]],
    ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
);
```

### 8.6 写操作语义

- `insertOne()`：返回 `InsertOneResult`；`getInsertedId()` 可能是 `ObjectId`、字符串或调用方提供的值。
- `insertMany()`：返回 `InsertManyResult`；不再有“单条走 insertOne”的特殊分支。
- `updateOne()/updateMany()/replaceOne()`：返回 `UpdateResult`。匹配但值未变化时 `modified = 0`、`matched = 1`，这是成功，不是 `false`。
- `deleteOne()/deleteMany()`：返回 `DeleteResult`；`deleted = 0` 是合法的“未命中”，不是 `false`。
- `bulkWrite()`：返回 `BulkWriteResult`；v2.3 的操作使用位置参数数组，例如 `['updateOne' => [$filter, $update, $options]]`。包装器对以下操作的过滤条件/插入文档做归一化：
  - `insertOne`：文档归一化；
  - `updateOne`、`updateMany`、`replaceOne`、`deleteOne`、`deleteMany`：过滤条件归一化。
  - 所有操作先整体归一化，再交给驱动，避免归一化阶段报错导致部分写入。
- 重复键、写冲突、批量部分成功等异常由驱动原样抛出；调用方需要检查 `BulkWriteException` 的部分结果。

### 8.7 异常契约

| 场景 | 异常 |
|---|---|
| 配置缺失/非法/未知键/选项冲突 | `InvalidArgumentException` |
| 参数类型错误 | `TypeError` |
| 连接失败/选择超时 | `MongoDB\Driver\Exception\ConnectionTimeoutException` 等 |
| 认证失败 | `MongoDB\Driver\Exception\AuthenticationException` |
| 重复键/写失败 | `MongoDB\Driver\Exception\BulkWriteException`（ext-mongodb 2.3 中继承 `ServerException`） |
| 命令/查询失败 | `MongoDB\Driver\Exception\CommandException` 等 |

库层不捕获、不转换这些异常，保留原始类型和堆栈，方便调用方按异常类型重试或降级。

### 8.8 原生逃生口

```php
$raw = $model->collection('users')->raw();       // MongoDB\Collection
$db  = $model->database();                       // MongoDB\Database
$client = $model->client();                      // MongoDB\Client
```

适用场景：

- 聚合、Change Stream、GridFS、索引管理、事务、CSFLE；
- 需要自定义 `typeMap` / `codec`；
- 需要原生 cursor 而不是数组。

使用 `raw()` 后，`_id` 归一化、数组解码、选项校验都由调用方自行负责。

---

## 9. 关键流程

### 9.1 `find()` / `iterate()`

```text
find(filter, options)
  -> iterate(filter, options)
       -> normalizeFilter(filter)
       -> raw->find(filter, options + 强制 typeMap)
       -> foreach cursor: normalizeDocument(document)
       -> yield
  -> 收集为数组返回
```

- `find()` 只是 `iterate()` 的收集器，避免两套归一化逻辑。
- `findOne()` 走原生 `findOne()`，同样强制 `typeMap` 后归一化。
- `countDocuments()` / `estimatedDocumentCount()` 不做文档归一化，只处理过滤条件。

### 9.2 写入流程

```text
insertOne(document)
  -> normalizeInsertDocument(document)   // 复制，不改原数组
  -> raw->insertOne(normalized, options)
  -> 返回 InsertOneResult
```

- `insertMany()` 对每个文档做归一化，全部成功后再调用驱动。
- 更新/删除只归一化 filter，不触碰 update/replacement 文档。
- 写入结果不做二次包装。

### 9.3 `bulkWrite()` 流程

```text
foreach operations:
  - insertOne: 归一化文档
  - updateOne/updateMany/replaceOne/deleteOne/deleteMany: 归一化 filter
  - 其他：原样（驱动会报错）
全部归一化完成后 -> raw->bulkWrite(normalizedOperations, options)
```

### 9.4 `ping()`

```text
database()->command(['ping' => 1])->toArray()[0]['ok'] == 1
```

- 连接失败、认证失败原样抛出。
- 不吞异常返回 false，避免健康检查误判。

---

## 10. 协程与阻塞模型

### 10.1 swoolefy 组件用法

```php
// Config/component/mongodb.php
use Swoolefy\Library\Mongodb\MongodbModel;

return [
    'mongodb' => function () {
        return new MongodbModel([
            'uri' => getenv('MONGO_URI'),
            'database' => getenv('MONGO_DB'),
            'id_key' => '_id',
            'object_id' => true,
        ]);
    },
];
```

```php
$mongo = \Swoolefy\Core\Application::getApp()->get('mongodb');
$users = $mongo->collection('users');   // ContainerObjectDto 会转发 __call
```

- `get()` 在 swoolefy 中是**按协程缓存**的单例：同一协程重复 `get('mongodb')` 返回同一个模型。
- `goApp()` 创建子协程时会建立独立的 `EventController` 与组件容器，子协程拿到新的模型/Client；不要把父协程的模型传给子协程。
- 需要把模型传给强类型函数时，用 `$mongo->getObject()` 取出真实 `MongodbModel`。
- 不要用 `makeNewObject()` 反复重建模型；同一协程内一个实例足够，且能复用数据库/collection 缓存。

### 10.2 阻塞边界

- 官方 Client 的调用是同步阻塞的；当前 Swoole 版本没有可用的透明 MongoDB hook（见 4.3）。
- 一次 Mongo 调用会阻塞当前 Worker 进程，期间该 Worker 上的其他协程都无法推进。
- 默认超时只能限制最坏阻塞时长，不能把阻塞变成非阻塞。
- 以下场景应移出 HTTP Worker：
  - 大批量导入/导出、复杂聚合、全表扫描；
  - 跨地域连接、网络抖动明显的环境；
  - 对接口延迟敏感的请求。
- 可放到 Task Worker、队列消费者、Cron/Daemon 或独立进程；本模块 API 在 CLI/协程环境都可直接使用。

### 10.3 超时与查询上限

| 配置 | 作用 |
|---|---|
| `serverSelectionTimeoutMS` | 选择可用节点最长时间 |
| `connectTimeoutMS` | 建立连接最长时间 |
| `socketTimeoutMS` | socket 读写最长时间 |
| 操作选项 `maxTimeMS` | 服务端执行查询/命令的最长时间 |

- 默认值见 7.3；业务可按环境覆盖。
- 长查询必须在操作选项里显式设置 `maxTimeMS`，不能只依赖 socket 超时。
- 不建议把 `socketTimeoutMS` 设为 0；无限等待会让 Worker 无法恢复。

### 10.4 连接数

- 每个 `MongodbModel` 持有一个 `MongoDB\Client`，内部有独立的驱动连接池。
- 按协程一个模型意味着高并发时会创建多个 Client；连接数随并发上升。
- 如果连接数成为瓶颈，可在 swoolefy 中按框架要求把 `mongodb` 注册为 `component_pools` 组件，由框架按请求复用模型；池化对象只能被一个协程持有，不能并发共享。
- 不建议在 Worker 启动时创建一个全局 Client 然后跨协程共享；这会让阻塞调用互相影响，也绕过框架的组件生命周期。

---

## 11. 使用示例

### 11.1 注册组件

```php
// Config/component/mongodb.php
return [
    'mongodb' => function () {
        return new \Swoolefy\Library\Mongodb\MongodbModel([
            'uri' => getenv('MONGO_URI'),
            'database' => getenv('MONGO_DB'),
            'uri_options' => [
                'serverSelectionTimeoutMS' => 3000,
                'connectTimeoutMS' => 2000,
                'socketTimeoutMS' => 30000,
            ],
        ]);
    },
];
```

### 11.2 基础 CRUD

```php
$mongo = \Swoolefy\Core\Application::getApp()->get('mongodb');
$users = $mongo->collection('users');

$insert = $users->insertOne(['name' => 'alice', 'age' => 20]);
$id = (string) $insert->getInsertedId();

$user = $users->findOne(['_id' => $id]);
// ['_id' => '...', 'name' => 'alice', 'age' => 20]

$update = $users->updateOne(['_id' => $id], ['$set' => ['age' => 21]]);
$update->getMatchedCount();   // 1
$update->getModifiedCount();  // 1

$list = $users->find(['age' => ['$gte' => 18]], ['sort' => ['age' => 1], 'limit' => 20]);

$delete = $users->deleteOne(['_id' => $id]);
$delete->getDeletedCount();   // 1
```

### 11.3 原子更新

```php
use MongoDB\Operation\FindOneAndUpdate;

$doc = $users->findOneAndUpdate(
    ['_id' => $id],
    ['$inc' => ['login_count' => 1]],
    ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
);

if ($doc === null) {
    // 未命中
}
```

### 11.4 业务模型继承

```php
final class UserModel extends \Swoolefy\Library\Mongodb\MongodbModel
{
    public function __construct()
    {
        parent::__construct([
            'uri' => getenv('MONGO_URI'),
            'database' => getenv('MONGO_DB'),
        ]);
    }

    public function findById(string $id): ?array
    {
        return $this->collection('users')->findOne(['_id' => $id]);
    }
}
```

### 11.5 事务（原生 API）

v1 不封装事务。需要事务时使用原生 session，并把 `session` 通过操作选项传入：

```php
$session = $mongo->client()->startSession();
try {
    $session->withTransaction(function () use ($session, $users) {
        $users->updateOne(
            ['_id' => $id],
            ['$inc' => ['balance' => -100]],
            ['session' => $session]
        );
        $users->updateOne(
            ['_id' => $toId],
            ['$inc' => ['balance' => 100]],
            ['session' => $session]
        );
    });
} finally {
    $session->endSession();
}
```

注意：事务要求副本集或分片集群；单机 MongoDB 容器不支持。

---

## 12. 旧 API 迁移

旧实现不做兼容别名。迁移对照：

| 旧写法 | 新写法 | 说明 |
|---|---|---|
| `new MongodbModel($uri, $uriOptions, $driverOptions)` | `new MongodbModel([...])` | 改配置数组；URI/数据库必填 |
| `$model->setDatabase('db')` | 构造配置 `'database' => 'db'` | 模型创建后不再切换默认库 |
| `$model->db()` / `dbInstance()` | `$model->database()` | 返回原生 `MongoDB\Database` |
| `$model->collection('users')` | 不变 | 返回 `MongodbCollection` |
| `$model->users` | 不变 | `__get` 便捷写法 |
| `$model->setIdKey('id')` | 配置 `'id_key' => 'id'` | 读/过滤/插入的别名语义见 8.3 |
| `$collection->insert($docs)` | `insertOne()` / `insertMany()` | 不再按数量推断 |
| `$collection->insertOne($doc)` 返回 int | 返回 `InsertOneResult` | 用 `getInsertedId()` 取主键 |
| `$collection->update(...)` | `updateOne()` / `updateMany()` | 返回 `UpdateResult` |
| `$collection->delete(...)` | `deleteOne()` / `deleteMany()` | 返回 `DeleteResult` |
| `$collection->count($filter)` | `countDocuments($filter)` | v2 已移除 `count()` |
| `$collection->findOneAndUpdate(...)` 返回 mixed | 返回 `?array` | 不再有 metadata；未命中为 `null` |
| `$collection->__call('aggregate', ...)` | `$collection->raw()->aggregate(...)` 或 `aggregate()` | `aggregate()` 不做 `_id` 归一化 |
| `$collection->__call(...)` 其他原生方法 | `$collection->raw()->...` | 显式逃生口 |

行为变化重点：

1. `_id` 只把 `ObjectId` 转字符串；int、字符串、复合 `_id` 保持原类型。
2. 过滤条件中的 24 位字符串会自动转 `ObjectId`；如果集合使用字符串主键，配置 `object_id => false`。
3. 写操作不再返回 `int|false`，而是原生结果对象。
4. `findOne()` 未命中返回 `null`，不再有未定义变量。
5. `find()` 仍然一次返回全部结果；大数据量改用 `iterate()`。

---

## 13. 测试方案

### 13.1 单元测试（不依赖 MongoDB）

| 测试文件 | 覆盖点 |
|---|---|
| `tests/MongoDb/MongodbConfigTest.php` | 必填项、未知键、URI 协议、数据库名、`id_key`、`object_id`、`typeMap`/`codec` 冲突、默认超时、`__debugInfo()` 脱敏 |
| `tests/MongoDb/IdNormalizerTest.php` | 24 位字符串转 ObjectId、非 24 位字符串保持、ObjectId 原样、`$eq/$ne/$in/$nin/$all`、顶层 `$and/$or/$nor`、`id_key` 映射、插入别名冲突、读结果 ObjectId/字符串/int/复合 `_id`、输出碰撞保护 |

单测不需要连接，可在宿主机或容器内跑。

### 13.2 集成测试（真实 MongoDB）

环境变量：

| 变量 | 说明 |
|---|---|
| `MONGO_URI` | 完整连接串，可含认证；不写入仓库 |
| `MONGO_DB` | 测试数据库；测试只操作 `phpunit_mongodb_` 前缀 collection，不 drop 整个库 |

没有 `MONGO_URI` 或连接失败时 `markTestSkipped`，错误信息不输出 URI。

测试文件：`tests/MongoDb/MongodbIntegrationTest.php`、`tests/MongoDb/MongodbCoroutineTest.php`。

覆盖场景：

- `insertOne` 返回主键；`insertMany` 数量正确，单条不走嵌套分支。
- `find` / `iterate` 返回数组；`_id` 为字符串；嵌套文档为数组。
- `findOne` 未命中返回 `null`，无 warning。
- 字符串 `_id` 过滤自动转 ObjectId；`$in` / `$ne` 过滤。
- `object_id => false` 时字符串主键原样读写。
- `updateOne` / `updateMany` 的 matched / modified / upserted 计数；值未变化时 modified=0 仍成功。
- `deleteOne` / `deleteMany` 的 deleted 计数；未命中为 0。
- `findOneAndUpdate`：默认 BEFORE、显式 AFTER、字符串 `returnDocument` 被拒绝、`includeResultMetadata` 被拒绝、未命中 null。
- `findOneAndReplace`、`findOneAndDelete`。
- `countDocuments`、`estimatedDocumentCount`、`distinct`。
- `bulkWrite` 混合操作与过滤条件归一化。
- `aggregate` 返回数组且不改写分组 `_id`。
- `ping()` 返回 true。
- 重复键异常原样抛出。
- `id_key => 'id'` 的读/过滤/插入别名行为与碰撞保护。

清理：`tearDown()` 删除本次创建的 `phpunit_mongodb_` 前缀 collection；不执行 dropDatabase。

### 13.3 协程兼容测试

`MongodbCoroutineTest` 在 `Swoole\Coroutine\run` + `Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` 下启动两个 `go()` 协程，各自创建模型并执行 CRUD + `ping()`，验证：

- 协程内创建模型、执行 CRUD 不报错；
- 每个协程使用独立模型/Client，不共享缓存；
- 不依赖 MongoDB 调用被 hook（该测试只验证兼容性，不验证非阻塞）。

### 13.4 swoolefy Test 服务端到端

新增：

- `Test/Common/MongodbVerify.php`：场景执行器；
- `Test/Controller/MongodbController.php`：HTTP 入口；
- `Test/Config/component/mongodb.php`：组件注册，读取 `MONGO_URI` / `MONGO_DB`；
- `Test/Router/Common/Mongodb.php`：路由注册 `/api/mongodb/verify`。

场景（实测全部通过）：

| 场景 | 断言 |
|---|---|
| `crud_and_id_normalization` | 插入、查询、更新、删除；`_id` 字符串归一化；嵌套文档为数组；`__get` 返回 collection |
| `component_instances` | 同一父协程两次 `get('mongodb')` 得到同一模型实例；`goApp` 子协程拿到不同模型实例 |
| `child_coroutine_crud` | 子协程独立执行 CRUD 与 `ping()`，数据正确 |
| `write_result_semantics` | matched/modified/upserted 语义；未命中删除为 0；`findOneAndUpdate` 默认 BEFORE、显式 AFTER；字符串 `returnDocument` 被拒绝 |
| `bulk_and_aggregate` | bulkWrite 位置参数混合操作与过滤条件归一化；aggregate 分组 `_id` 不被改写；distinct 保留原生 ObjectId |

执行方式：容器内启动 Test 服务，`curl` 调用验证入口，检查返回 JSON 与服务日志；测试 collection 使用固定前缀并在场景结束时清理。由于 Mongo 调用阻塞 Worker，E2E 只做小数据量 CRUD，不做并发压测。

### 13.5 验收清单

- [x] 容器内 `phpunit` 全部通过（单元 + 集成 + 协程，共 65 项）。
- [x] 真实 MongoDB 6.0 上 CRUD、原子操作、bulkWrite、aggregate、count、distinct 行为符合第 8 节。
- [x] `returnDocument` 字符串被拒绝；整数常量可用；`includeResultMetadata` 被拒绝。
- [x] `findOne` 未命中返回 null，无 warning。
- [x] 更新/删除不再用 `false` 表示 0。
- [x] `typeMap` 强制数组，读结果无 `BSONDocument` / `BSONArray`。
- [x] 配置 fail-fast，异常/调试输出不含 URI 凭据。
- [x] swoolefy E2E 通过，父子协程模型实例隔离。
- [x] README 写明阻塞模型、超时、`_id` 语义、`raw()` 用法。
- [x] 旧临时测试 `tests/MongoDb/MongoDb.php` 删除，无死代码。
- [x] 未启用 `SWOOLE_HOOK_MONGODB`，未修改现有 MongoDB 容器凭据。

---

## 14. 实施步骤

| 阶段 | 内容 | 状态 |
|---|---|---|
| P1 | `MongodbConfig` + `IdNormalizer` + 对应单测 | 已完成（44 项单测通过） |
| P2 | 重写 `MongodbCollection`：读、写、原子操作、bulkWrite、aggregate | 已完成 |
| P3 | 重写 `MongodbModel`：懒连接、数据库/collection 缓存、ping、脱敏 | 已完成 |
| P4 | 集成测试 + 容器内运行 | 已完成（ext-mongodb 2.3.3 / MongoDB 6.0） |
| P5 | swoolefy E2E：组件、控制器、验证进程 | 已完成（5 个场景通过） |
| P6 | README、迁移说明、删除旧临时测试、最终回归 | 已完成 |

实施期间不改 `composer.json` 的依赖声明；不提交、不推送，除非另行要求。

---

## 15. 风险与对策

| 编号 | 风险 | 影响 | 对策 |
|---|---|---|---|
| R1 | Mongo 调用阻塞 Worker | 接口延迟、协程饿死 | 默认超时；长操作移到 Task Worker/队列；README 显著提示 |
| R2 | 每协程一个 Client 导致连接数上升 | Mongo 连接压力 | 控制并发；必要时使用 `component_pools`；监控连接数 |
| R3 | `v2.3.x-dev` 是开发版本，API 可能变化 | 升级时行为漂移 | 只用稳定公开 API；集成测试覆盖；后续锁定正式版本 |
| R4 | 现有 MongoDB 容器认证凭据未确认 | 集成测试无法连接 | 使用 `MONGO_URI` 注入或临时无认证容器；不改容器数据卷 |
| R5 | 宿主机 ext-mongodb 1.19.4 不满足 ^2.3 | 本机无法跑真实连接测试 | 集成测试统一在 `swoolefy-php85-v62` 容器执行 |
| R6 | `id_key` 与业务字段重名 | 字段被覆盖/过滤错误 | 读结果碰撞时保留业务字段；插入同时出现时直接报错；README 标注保留别名 |
| R7 | `aggregate`/`distinct` 不做 `_id` 归一化 | 与 `find()` 行为不一致 | 文档明确；需要归一化时在业务层处理或用 `raw()` |
| R8 | 调用方误以为开启 `SWOOLE_HOOK_ALL` 就能非阻塞 | 生产环境阻塞 | 4.3 与 README 明确说明；不启用 `SWOOLE_HOOK_MONGODB` |
| R9 | 调用方把模型跨协程共享 | 阻塞叠加、缓存竞态 | 组件按协程单例；README 明确禁止跨协程传递 |
| R10 | 大结果集一次性读入内存 | Worker 内存上涨 | 提供 `iterate()`；文档建议 `limit`/分批 |

---

## 16. 实施决策

实施时采用以下取舍：

1. **保留 `id_key`**：默认 `_id`；配置别名时按 8.3 处理碰撞，插入同时出现 `_id` 与别名时 fail-fast。
2. **写操作返回驱动原生结果对象**：`InsertOneResult`、`InsertManyResult`、`UpdateResult`、`DeleteResult`、`BulkWriteResult`。
3. **不封装事务**：v1 使用原生 `startSession()->withTransaction()`；模块不额外增加 `withTransaction()`。
4. **保留 `aggregate(): array`**：聚合结果不做 `_id` 归一化；需要原生 cursor 时用 `raw()->aggregate()`。

---

## 17. 参考

- MongoDB PHP Library 文档：<https://www.mongodb.com/docs/php-library/current/>
- MongoDB PHP 驱动文档：<https://www.php.net/manual/en/set.mongodb.php>
- 本地依赖源码：`../vendor/mongodb/mongodb/src/Collection.php`、`Client.php`、`Operation/FindOneAndUpdate.php`
- Swoole hook 常量：`../vendor/swoole/ide-helper/src/swoole/constants.php`
- 旧实现：[MongodbModel.php](../src/Mongodb/MongodbModel.php)、[MongodbCollection.php](../src/Mongodb/MongodbCollection.php)
