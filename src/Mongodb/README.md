# Mongodb

对 `mongodb/mongodb` v2.3 的轻量封装：统一数组解码、集中管理 `_id` 归一化、写操作返回驱动原生结果对象。不是 ODM，不提供 Schema、关联、查询构造器或迁移工具。

命名空间：`Swoolefy\Library\Mongodb`  
依赖：`mongodb/mongodb` `v2.3.x-dev`、`ext-mongodb` `^2.3`  
PHP：`>= 8.4`

---

## 最小用法

```php
use Swoolefy\Library\Mongodb\MongodbModel;

$model = new MongodbModel([
    'uri' => getenv('MONGO_URI'),      // mongodb:// 或 mongodb+srv://
    'database' => getenv('MONGO_DB'),
]);

$users = $model->collection('users');

$inserted = $users->insertOne([
    '_id' => '507f1f77bcf86cd799439011',
    'name' => 'demo',
]);

$inserted->getInsertedId();                 // MongoDB\BSON\ObjectId
$document = $users->findOne(['_id' => '507f1f77bcf86cd799439011']);
$document['_id'];                           // 字符串 "507f1f77bcf86cd799439011"
```

`find()` / `findOne()` 返回 PHP 数组；嵌套文档与数组也会解码为数组。

---

## 配置

```php
new MongodbModel([
    'uri' => getenv('MONGO_URI'),
    'database' => getenv('MONGO_DB'),
    'uri_options' => [
        'serverSelectionTimeoutMS' => 3000,
        'connectTimeoutMS' => 2000,
        'socketTimeoutMS' => 30000,
    ],
    'driver_options' => [],
    'id_key' => '_id',
    'object_id' => true,
]);
```

| 键 | 类型 | 必填 | 默认 | 说明 |
|---|---|---|---|---|
| `uri` | string | 是 | - | 必须以 `mongodb://` 或 `mongodb+srv://` 开头，不提供 localhost 默认值 |
| `database` | string | 是 | - | 非空、≤ 64 字节、不含 MongoDB 非法字符 |
| `uri_options` | array | 否 | 见下 | 直接传给 `MongoDB\Client` |
| `driver_options` | array | 否 | `[]` | 直接传给 `MongoDB\Client`；禁止 `typeMap` / `codec` |
| `id_key` | string | 否 | `_id` | 读结果主键别名，也是过滤条件别名 |
| `object_id` | bool | 否 | `true` | 是否自动转换 24 位十六进制字符串与 `ObjectId` |

默认 `uri_options`（用户值优先）：

| 选项 | 默认值 |
|---|---|
| `serverSelectionTimeoutMS` | 3000 |
| `connectTimeoutMS` | 2000 |
| `socketTimeoutMS` | 30000 |

配置在构造阶段 fail-fast：未知键、缺失 URI/数据库、URI 协议错误、`typeMap`/`codec` 冲突、非法 `id_key` 都会抛 `InvalidArgumentException`。异常信息与 `__debugInfo()` 不包含完整 URI。

### 账号与密码

账号密码没有独立配置项，必须写在 `uri` 中；顶层出现 `username` / `password` 会被当作未知键抛 `InvalidArgumentException`。

```php
new MongodbModel([
    'uri' => sprintf(
        'mongodb://%s:%s@%s/?authSource=%s',
        rawurlencode((string) getenv('MONGO_USER')),
        rawurlencode((string) getenv('MONGO_PASSWORD')),
        (string) getenv('MONGO_HOST'),   // 例如 127.0.0.1:27017
        'admin'
    ),
    'database' => (string) getenv('MONGO_DB'),
]);
```

推荐让 `uri` 整体走环境变量，凭据不进入代码库：

```bash
export MONGO_URI='mongodb://user:password@127.0.0.1:27017/?authSource=admin'
export MONGO_DB='swoolefy_test'
```

```php
new MongodbModel([
    'uri' => (string) getenv('MONGO_URI'),
    'database' => (string) getenv('MONGO_DB'),
]);
```

- 用户名或密码含 `@`、`:`、`/`、`%`、`?`、`#` 等字符时必须用 `rawurlencode()` 编码。
- `authSource` 省略时，驱动取 URI 路径中的库；URI 没有路径则默认 `admin`。本模块的 `database` 配置不会写入 URI，因此账号建在业务库时必须显式指定 `authSource=业务库`。
- `mongodb+srv://user:password@cluster.example.com/?authSource=admin` 写法相同。
- 凭据只能放在 URI 中，`uri_options` 不支持 `username` / `password`。

| 账号创建位置 | `authSource` |
|---|---|
| `admin`（root 用户） | `admin` |
| 业务库（如 `swoolefy_test`） | 业务库名 |

---

## `_id` 语义

`object_id = true`（默认）：

| 场景 | 行为 |
|---|---|
| 过滤条件 `_id` 为 24 位十六进制字符串 | 转 `MongoDB\BSON\ObjectId` |
| 过滤条件 `_id` 为 `ObjectId` | 原样 |
| 过滤条件 `_id` 为其他字符串 / int / 复合数组 | 原样 |
| 过滤条件 `_id` 使用 `$eq` / `$ne` / `$in` / `$nin` / `$all` | 递归转换其中的 24 位字符串 |
| 顶层 `$and` / `$or` / `$nor` | 递归处理每个子条件 |
| 插入文档 `_id` 为 24 位十六进制字符串 | 复制后转 `ObjectId`，不修改调用方数组 |
| 读出的 `_id` 为 `ObjectId` | 转字符串 |
| 读出的 `_id` 为 int / string / 复合数组 | 原样保留 |
| 嵌套字段里的 `ObjectId` | 原样保留，不递归改写 |

`object_id = false`：不做任何字符串 ↔ `ObjectId` 转换，过滤条件需自行传 `ObjectId`，读结果保留原生对象。适用于字符串主键、UUID、复合主键或需要原生 BSON 类型的场景。

---

## `id_key` 别名

配置 `'id_key' => 'id'` 时：

- 读结果：`_id` 的值放入 `id` 并删除 `_id`；若文档已有非空 `id` 业务字段，则保留 `id` 与 `_id`，避免覆盖数据。
- 过滤条件：`id` 映射为 `_id`；同时出现 `_id` 与 `id` 时以 `_id` 为准。
- 插入文档：只有 `id` 时映射为 `_id` 并删除 `id`；同时出现 `_id` 与 `id` 时抛 `InvalidArgumentException`。
- `id_key` 是保留别名，业务文档不应再使用同名字段。

---

## 读操作

```php
$collection->find(['status' => 'active'], ['sort' => ['created_at' => -1], 'limit' => 20]);
$collection->findOne(['_id' => $id]);
$collection->iterate(['status' => 'active']);       // Generator，适合大数据量
$collection->countDocuments(['status' => 'active']);
$collection->estimatedDocumentCount();
$collection->distinct('status', ['type' => 'vip']); // ObjectId 不转字符串
$collection->aggregate([
    ['$group' => ['_id' => '$status', 'total' => ['$sum' => 1]]],
]);
```

- `findOne()` 未命中返回 `null`。
- `iterate()` 逐条归一化；提前 `break` 时生成器销毁，cursor 随引用释放。
- `aggregate()` 返回数组列表，但**不**改写 `_id`：分组结果的 `_id` 可能是业务键。需要原生 cursor 时用 `raw()->aggregate()`。
- `distinct()` 返回原生值数组，与 `find()` 的 `_id` 语义不同。
- 读方法收到 `typeMap` 或 `codec` 选项会抛 `InvalidArgumentException`；库固定 `['root' => 'array', 'document' => 'array', 'array' => 'array']`。

---

## 原子读写

```php
use MongoDB\Operation\FindOneAndUpdate;

$before = $collection->findOneAndUpdate(
    ['_id' => $id],
    ['$inc' => ['login_count' => 1]]
);

$after = $collection->findOneAndUpdate(
    ['_id' => $id],
    ['$inc' => ['login_count' => 1]],
    ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
);

$collection->findOneAndReplace($filter, $replacement, $options);
$collection->findOneAndDelete($filter);
```

- `returnDocument` 只接受整数常量 `RETURN_DOCUMENT_BEFORE`(1) / `RETURN_DOCUMENT_AFTER`(2)，字符串会抛 `InvalidArgumentException`；默认与驱动一致，返回更新前文档。
- `findOneAndDelete()` 不接受 `returnDocument`。
- 三者均拒绝 `includeResultMetadata`（v2.3 已移除该能力），未命中返回 `null`。
- 返回文档经过 `_id` 归一化；更新/替换文档本身不做 `_id` 转换。

---

## 写操作

```php
$inserted = $collection->insertOne($document);   // MongoDB\InsertOneResult
$inserted->getInsertedId();

$many = $collection->insertMany($documents);     // MongoDB\InsertManyResult
$many->getInsertedIds();

$updated = $collection->updateOne($filter, $update);   // MongoDB\UpdateResult
$updated->getMatchedCount();
$updated->getModifiedCount();
$updated->getUpsertedCount();

$deleted = $collection->deleteOne($filter);      // MongoDB\DeleteResult
$deleted->getDeletedCount();
```

- 写操作返回驱动原生结果对象，不用 `int|false` 猜测成功与否。
- 匹配但值未变化：`matched = 1`、`modified = 0`，这是成功。
- 删除未命中：`deleted = 0`，这也是合法结果。
- `insertMany()` 对每个文档归一化；单条文档不会被嵌套包装。

### `bulkWrite()`

v2.3 使用位置参数数组：

```php
$result = $collection->bulkWrite([
    ['insertOne'  => [['_id' => $id1, 'n' => 1]]],
    ['updateOne'  => [['_id' => $id1], ['$set' => ['n' => 2]]]],
    ['replaceOne' => [['_id' => $id2], ['n' => 3], ['upsert' => true]]],
    ['deleteMany' => [['n' => ['$lt' => 0]]]],
]);

$result->getInsertedCount();
$result->getModifiedCount();
$result->getDeletedCount();
```

包装器会归一化 `insertOne` 的文档以及 `updateOne` / `updateMany` / `replaceOne` / `deleteOne` / `deleteMany` 的过滤条件，并且先整体归一化再交给驱动，避免归一化报错导致部分写入。重复键、写冲突、批量部分成功等异常由驱动原样抛出。

---

## 原生逃生口

```php
$model->client();                               // MongoDB\Client
$model->database();                             // MongoDB\Database
$model->database('archive');                    // 跨库
$model->collection('logs', 'archive');          // 跨库 collection
$model->collection('users')->raw();             // MongoDB\Collection
```

需要事务、Change Stream、GridFS、索引管理、CSFLE、自定义 `typeMap`/`codec` 或原生 cursor 时使用原生对象。`raw()` 之后 `_id` 归一化、数组解码与选项校验都由调用方负责。

---

## 异常

| 场景 | 异常 |
|---|---|
| 配置缺失/非法/未知键/选项冲突 | `InvalidArgumentException` |
| 参数类型错误 | `TypeError` |
| 连接失败/选择超时 | `MongoDB\Driver\Exception\ConnectionTimeoutException` 等 |
| 认证失败 | `MongoDB\Driver\Exception\AuthenticationException` |
| 重复键/写失败 | `MongoDB\Driver\Exception\BulkWriteException` |
| 命令/查询失败 | `MongoDB\Driver\Exception\CommandException` 等 |

库层不捕获、不转换驱动异常，保留原始类型和堆栈。库层不做重试，驱动自身的 retryable reads/writes 保持默认。

---

## 协程与阻塞

官方 `MongoDB\Client` 的调用是**同步阻塞**的；Swoole 的 `SWOOLE_HOOK_ALL` 不包含 `SWOOLE_HOOK_MONGODB`，本模块也不启用 `SWOOLE_HOOK_MONGODB`。

- 一次 Mongo 调用会阻塞当前 Worker 进程，期间该 Worker 上的其他协程无法推进。
- 默认超时只限制最坏阻塞时长，不能把阻塞变成非阻塞。
- 大批量导入/导出、复杂聚合、全表扫描、对延迟敏感的请求应放到 Task Worker、队列或独立进程。
- 长查询还需在操作选项里显式设置 `maxTimeMS`，不能只依赖 `socketTimeoutMS`。

每个 `MongodbModel` 持有独立 Client 与 collection 缓存，同一实例不要跨协程共享。swoolefy 组件 `get('mongodb')` 已按协程缓存实例：

```php
// Config/component/mongodb.php
use Swoolefy\Library\Mongodb\MongodbModel;

return [
    'mongodb' => static fn () => new MongodbModel([
        'uri' => getenv('MONGO_URI'),
        'database' => getenv('MONGO_DB'),
    ]),
];
```

```php
$mongo = \Swoolefy\Core\Application::getApp()->get('mongodb');
$users = $mongo->collection('users');   // ContainerObjectDto 转发 __call
```

`goApp()` 子协程有独立的组件容器，会拿到新的 `MongodbModel`；不要把父协程的模型传给子协程。

---

## 从旧 API 迁移

旧实现不保留别名：

| 旧实现 | 新用法 |
|---|---|
| `new MongodbModel($uri, $uriOptions, $driverOptions)` | `new MongodbModel(['uri' => ..., 'database' => ...])` |
| `setDatabase()` / `dbInstance()` / `db()` | `database()` / `client()` |
| `setIdKey()` / `getIdKey()` | 配置项 `id_key` |
| `insert($documents)` / `insertOne()` 返回 int | `insertOne()` / `insertMany()`，读取原生结果对象 |
| `update()` / `updateOne()` / `updateMany()` 返回 int/false | `updateOne()` / `updateMany()`，检查 `getMatchedCount()` 等 |
| `delete()` / `deleteOne()` / `deleteMany()` 返回 int/false | `deleteOne()` / `deleteMany()`，检查 `getDeletedCount()` |
| `count()` | `countDocuments()` / `estimatedDocumentCount()` |
| `__call()` 直通原生方法 | `raw()` 后调用原生方法 |

---

## 测试

```bash
# 单元测试（配置校验、_id 归一化）
php vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/MongoDb

# 集成测试：需要 ext-mongodb >= 2.3 与可用的 MongoDB
MONGO_URI='mongodb://127.0.0.1:27017' MONGO_DB='phpunit_library' \
  php vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/MongoDb
```

集成测试只操作 `phpunit_mongodb_` 前缀的 collection，结束后逐个删除，不执行 `dropDatabase`。
