# Db 模块

基于 PDO 的协程友好数据库组件，提供 **连接层（PDOConnection）**、**查询构造器（Query / BaseQuery）**、**模型（Model）**，并内置事务、软删除、多租户隔离、分页、类型转换与 SQL 拦截器。

命名空间：`Swoolefy\Library\Db`

---

## 目录

- [架构概览](#架构概览)
- [目录结构](#目录结构)
- [支持的驱动](#支持的驱动)
- [连接配置](#连接配置)
- [Query Builder](#query-builder)
- [Model（实体）](#model实体)
- [软删除 SoftDelete](#软删除-softdelete)
- [多租户 TenantScope](#多租户-tenantscope)
- [事务](#事务)
- [分页](#分页)
- [聚合 / JOIN / 分块](#聚合--join--分块)
- [模型事件与属性](#模型事件与属性)
- [SQL 拦截器](#sql-拦截器)
- [协程与调试注意点](#协程与调试注意点)

---

## 架构概览

```
┌─────────────────────────────────────────────────────────┐
│  Facade\Db::table() / Model::query() / withoutTenantScope │
└───────────────────────────┬─────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────┐
│  Query / BaseQuery（Where / Join / Aggregate / Paginate） │
│  + Model Scope（tenant / soft-delete）                    │
└───────────────────────────┬─────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────┐
│  AbstractBuilder（Mysql / Pgsql / Sqlite / Oracle）       │
└───────────────────────────┬─────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────┐
│  PDOConnection（Mysql / Pgsql / …）                       │
│  · 断线重连 · 事务 / savepoint · SqlInterceptor           │
│  · TenantLineInterceptor（原生 SQL 兜底改写）              │
└─────────────────────────────────────────────────────────┘
```

| 层次 | 职责 |
|------|------|
| `Facade\Db` | 按 `default_db` 取连接，快速写 Query |
| `Model` | ActiveRecord：`save` / `delete` / 事件 / casts / Scope |
| `Query` | 链式条件、分页、聚合、增删改查 |
| `PDOConnection` | PDO 生命周期、事务、拦截器、元数据 |

---

## 目录结构

```
Db/
├── PDOConnection.php          # 连接基类
├── Mysql.php / Pgsql.php / Sqlite.php / Oracle.php
├── Query.php / BaseQuery.php  # 查询构造器
├── Model.php                  # 模型基类
├── Fetch.php                  # 结果拉取辅助
├── Collection.php / Paginator.php
├── Facade/Db.php              # 静态门面
├── AbstractBuilder.php + Builder/*
├── Concern/
│   ├── WhereQuery.php         # where / orWhere / …
│   ├── JoinAndViewQuery.php
│   ├── AggregateQuery.php     # count / sum / …
│   ├── SoftDelete.php
│   ├── TenantScope.php        # Model 层租户条件
│   ├── TenantScopeContext.php
│   ├── QueryPersistence.php   # insert/update/delete 持久化
│   ├── Attribute.php / TimeStamp.php / ModelEvent.php
│   └── Transaction.php
├── Interceptor/
│   ├── SqlInterceptorInterface.php
│   ├── TenantLineInterceptor.php
│   ├── TenantBootstrap.php
│   └── TenantLineHandlerInterface.php
└── Casts/TypeCasts.php
```

---

## 支持的驱动

| 类 | DSN |
|----|-----|
| `Swoolefy\Library\Db\Mysql` | `mysql:…` |
| `Swoolefy\Library\Db\Pgsql` | `pgsql:…` |
| `Swoolefy\Library\Db\Sqlite` | `sqlite:…` |
| `Swoolefy\Library\Db\Oracle` | Oracle PDO |

在 swoolefy 中通常通过组件工厂注册为 `db` / `pg` 等 DI 名。

---

## 连接配置

### 1. 组件注册（swoolefy）

`App/Config/component/database.php`（示例）：

```php
$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    'db' => function () use ($dc) {
        return new \Swoolefy\Library\Db\Mysql($dc['mysql_db']);
    },
    'pg' => function () use ($dc) {
        return new \Swoolefy\Library\Db\Pgsql($dc['pg_db']);
    },
];
```

`app.php` 中指定默认库（供 `Db::` 门面使用）：

```php
'default_db' => 'db',
```

### 2. 配置项（`dc.php` / env）

| 配置项 | 说明 | 默认相关 |
|--------|------|----------|
| `hostname` / `hostport` / `database` | 主机、端口、库名 | — |
| `username` / `password` | 账号 | — |
| `charset` | 字符集 | `utf8mb4` |
| `prefix` | 表前缀 | `''` |
| `break_reconnect` | 断线自动重连 | `true` |
| `support_savepoint` | 嵌套事务 savepoint | `false` |
| `debug` / `print_sql` | SQL 调试与打印 | `0` |
| `fetch_type` | PDO fetch 模式 | `PDO::FETCH_ASSOC` |
| `auto_param_bind` | 自动参数绑定 | `true`（业务配置） |

示例：

```php
'mysql_db' => [
    'type'              => 'mysql',
    'hostname'          => env('DB_HOST_NAME', '127.0.0.1'),
    'database'          => env('DB_HOST_DATABASE'),
    'username'          => env('DB_USER_NAME'),
    'password'          => env('DB_PASSWORD'),
    'hostport'          => env('DB_HOST_PORT'),
    'charset'           => 'utf8mb4',
    'break_reconnect'   => true,
    'support_savepoint' => false,
    'debug'             => 1,
    'print_sql'         => 1,
],
```

### 3. 直接拿连接

```php
use Swoolefy\Core\Application;

/** @var \Swoolefy\Library\Db\Mysql $db */
$db = Application::getApp()->get('db');
```

---

## Query Builder

### Facade：`Db`

```php
use Swoolefy\Library\Db\Facade\Db;

// 使用 default_db
$rows = Db::table('tbl_order')
    ->where('user_id', '=', 1001)
    ->order('order_id', 'desc')
    ->limit(10)
    ->select();

// 指定组件名
$rows = Db::connect('pg')->table('users')->where('id', 1)->find();
```

### 常用条件

```php
Db::table('tbl_order')
    ->where('order_status', '=', 1)
    ->whereIn('user_id', [1, 2, 3])
    ->whereBetween('order_amount', [10, 100])
    ->whereNull('deleted_at')
    ->whereLike('remark', '%vip%')
    ->where(function ($q) {
        $q->where('order_status', 1)->orWhere('order_status', 2);
    })
    ->field('order_id,user_id,order_amount')
    ->order('order_id', 'desc')
    ->select();
```

`where($subQuery)` 可合并另一个 Query 的条件（含 bind）。

### 增删改

```php
// insert
$id = Db::table('tbl_order')->insertGetId([
    'user_id' => 1001,
    'order_amount' => 99.5,
]);

// 批量
Db::table('tbl_order')->insertAll([
    ['user_id' => 1, 'order_amount' => 10],
    ['user_id' => 2, 'order_amount' => 20],
]);

// update
Db::table('tbl_order')
    ->where('order_id', '=', $id)
    ->update(['order_status' => 2]);

// delete
Db::table('tbl_order')->where('order_id', '=', $id)->delete();
```

### 单行 / 单列

```php
$row   = Db::table('tbl_order')->where('order_id', $id)->find();   // array|null
$first = Db::table('tbl_order')->order('order_id', 'desc')->first();
$amt   = Db::table('tbl_order')->where('order_id', $id)->value('order_amount');
$ids   = Db::table('tbl_order')->where('user_id', 1)->column('order_id');
```

### 原生 SQL

```php
$db = Application::getApp()->get('db');
$rows = $db->createCommand('SELECT * FROM tbl_order WHERE user_id = ?', [1001])
    ->queryAll();
```

或通过 Query：

```php
use Swoolefy\Library\Db\Raw;

Db::table('tbl_order')
    ->where('order_amount', '>', new Raw('0'))
    ->field(new Raw('COUNT(*) AS c'))
    ->select();
```

---

## Model（实体）

### 定义

```php
namespace App\Model;

use Swoolefy\Core\Application;
use Swoolefy\Library\Db\Model;
use Swoolefy\Library\Db\Concern\SoftDelete;
use Swoolefy\Library\Db\Casts\TypeCasts;

class Order extends Model
{
    use SoftDelete;

    protected static $table = 'tbl_order';
    protected $pk = 'order_id';

    protected $casts = [
        'json_data'    => TypeCasts::TYPE_ARRAY,
        'order_amount' => TypeCasts::TYPE_FLOAT,
    ];

    public function getConnection()
    {
        if (is_object($this->connection)) {
            return $this->connection;
        }
        return Application::getApp()->get('db');
    }

    // 修改器 / 获取器（可选）
    public function setOrderProductIdsAttr($value)
    {
        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    }

    public function getOrderProductIdsAttr($value)
    {
        return json_decode((string) $value, true);
    }
}
```

### CRUD

```php
// 新增
$order = new Order();
$order->user_id = 1001;
$order->order_amount = 88.0;
$order->json_data = ['channel' => 'app'];
$order->save();                      // insert
$pk = $order->order_id;

// 更新：先 load 再改
$order = new Order();
$order->loadById($pk);               // 或 loadOne(['order_id' => $pk])
$order->order_status = 2;
$order->save();                      // update

// 查询构造器（返回 Query；find/first 可回填 Model）
$list = Order::query()
    ->where('user_id', '=', 1001)
    ->order('order_id', 'desc')
    ->limit(20)
    ->select();

$row = Order::query()->where('order_id', '=', $pk)->first();

// 删除（启用 SoftDelete 时为软删）
$order->delete();
```

### 常用入口

| 方法 | 说明 |
|------|------|
| `Order::query()` / `$m->getQuery()` | 带表名 + 自动应用 TenantScope / SoftDelete |
| `$m->newQuery()` | 新 Query 并 `setModel`，需自行 `table()`（复杂 SQL） |
| `Order::withoutTenantScope()` | 关闭租户过滤（见下文） |
| `Order::withoutTrashed()` | 关闭软删过滤（见 SoftDelete） |

---

## 软删除 SoftDelete

在 Model 上 `use SoftDelete`，默认字段 `deleted_at`（可用 `protected static $softDeleteField` 覆盖）。

```php
// 普通查询：自动排除已软删
Order::query()->where('user_id', 1)->select();

// Entity::delete() → UPDATE deleted_at = now
$order->delete();

// 含已删记录
$trashed = Order::withoutTrashed()
    ->where('order_id', '=', $id)
    ->first();

// 物理删除（配合 withoutTrashed + delete(true)，以项目实际 API 为准）
Order::withoutTrashed()->where('order_id', $id)->delete(true);

// 恢复
$order->restore($id);
```

---

## 多租户 TenantScope

两层防护：

1. **Model Scope**：`query()` / `getQuery()` 自动 `WHERE table.tenant_id = ?`
2. **SQL 拦截器**：`TenantLineInterceptor` 改写原生 SQL / JOIN / EXISTS 等

### 启用

在应用启动（如 `Event.php`）注册：

```php
use Swoolefy\Library\Db\Interceptor\TenantBootstrap;
use Swoolefy\Library\Db\Interceptor\TenantLineDemoHandler;
// 或实现 TenantLineHandlerInterface 的自定义 Handler

TenantBootstrap::register(new TenantLineDemoHandler());
```

Handler 需实现：

- `getTenantId(): string` — 当前租户；**空字符串在租户表上会 fail-closed 抛异常**
- `getTenantIdColumn(): string` — 默认 `tenant_id`
- `ignoreTable(string $tableName): bool` — 元数据不可用时的兜底（表无该字段会自动跳过）

请求内设置租户（示例用协程 Context）：

```php
\Swoolefy\Core\Coroutine\Context::set('tenant_id', '100');
```

### 跨租户查询：`withoutTenantScope`

管理端、对账、运维脚本等需要跨租户时：

```php
// 不追加 tenant_id，同时跳过 TenantLineInterceptor
$list = Order::withoutTenantScope()
    ->where('order_id', '>', 0)
    ->select();

$count = Order::withoutTenantScope()->count();

// 指定连接
Order::withoutTenantScope($pdoConnection)->find();
```

| 方式 | Model Scope | TenantLineInterceptor |
|------|-------------|------------------------|
| `Order::query()` | 自动加租户条件 | 会改写 SQL |
| `Order::withoutTenantScope()` | 关闭 | 同步跳过 |

内部通过 Query option `without_tenant_scope` + 连接上的 `beginIgnoreTenantInterceptor()` 实现，子查询 `newQuery()` 会继承该 option。

---

## 事务

### 闭包事务（推荐）

连接层：

```php
$db = Application::getApp()->get('db');

$db->transaction(function () use ($db) {
    // 业务…
});
```

Model 内部可用 `$this->transaction(function () { … })`（已在事务中则不再嵌套 begin）。

### 手动事务

```php
$db = Application::getApp()->get('db');
$db->beginTransaction();
try {
    // …
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    throw $e;
}
```

### 嵌套事务

- `support_savepoint = true`：内层用 SAVEPOINT
- `support_savepoint = false`：内层只增加计数；内层 `rollback` 会标记外层最终真正回滚

协程内请保证同一请求使用同一连接实例（框架 DI / 协程单例），避免跨协程共用一个 PDO 事务。

---

## 分页

### `paginate`（页码分页）

```php
$page = Order::query()
    ->where('user_id', '=', 1001)
    ->order('order_id', 'desc')
    ->paginate(15, 1);   // listRows, page

// 或配置数组
$page = Order::query()->paginate([
    'list_rows' => 20,
    'page'      => 2,
    'query'     => ['user_id' => 1001],
], 2);

$items = $page->items();   // Collection
$total = $page->total();
```

### `paginateX`（游标 / 大数据）

适合无限滚动，按主键（或指定字段）向前翻：

```php
$pageX = Order::query()
    ->where('user_id', '=', 1001)
    ->paginateX(20, $lastId, 'order_id', 'desc');
```

---

## 聚合 / JOIN / 分块

```php
$n = Order::query()->where('user_id', 1)->count();
$sum = Order::query()->where('user_id', 1)->sum('order_amount');
$avg = Order::query()->avg('order_amount');
$max = Order::query()->max('order_amount');

// clone：count 不影响后续 select 的 options
$q = Order::query()->where('user_id', 1);
$total = (clone $q)->count();
$list  = $q->limit(10)->select();

// JOIN
Db::table('tbl_order')->alias('o')
    ->join('tbl_user u', 'o.user_id = u.user_id')
    ->where('o.order_status', 1)
    ->field('o.*,u.user_name')
    ->select();

// 分块 / 游标
Order::query()->where('user_id', 1)->chunk(100, function ($rows) {
    // …
});

foreach (Order::query()->where('user_id', 1)->cursor() as $row) {
    // …
}
```

---

## 模型事件与属性

### 事件常量（`Model::EVENTS`）

| 事件 | 时机 |
|------|------|
| `BeforeSave` / `AfterSave` | 插入与更新共用前后钩子 |
| `BeforeInsert` / `AfterInsert` | 插入 |
| `BeforeUpdate` / `AfterUpdate` | 更新 |
| `BeforeDelete` / `AfterDelete` | 删除 |
| `*Transaction` | 事务边界内的插入/更新钩子 |

在 Model 或 Trait 中实现对应方法（如 `onBeforeInsert`），具体命名以 `ModelEvent` 约定为准。

### Casts

`Casts\TypeCasts`：`string` / `int` / `float` / `bool` / `array` / `json` / `object` / `datetime` / `timestamp`。

```php
protected $casts = [
    'json_data' => 'array',
    'flag'      => 'bool',
];
```

---

## SQL 拦截器

实现 `SqlInterceptorInterface`，对即将执行的 SQL 做改写或审计。

租户一键注册：

```php
TenantBootstrap::register($handler);                    // 全局
TenantBootstrap::registerForConnection($db, $handler);  // 单连接
```

`withoutTenantScope()` 路径会 `beginIgnoreTenantInterceptor()`，避免 Scope 关闭后仍被拦截器二次改写。

---

## 协程与调试注意点

1. **协程隔离**：每个协程应使用自己的连接（swoolefy 组件 + `goApp` / 协程 Context）；不要跨协程共享同一个 PDO 事务。
2. **租户 ID**：租户表在 `getTenantId()` 为空时会抛 `DbException`（fail-closed），请求入口务必设置租户。
3. **调试 SQL**：配置 `debug` + `print_sql`，或路由上 `->enableDbDebug()`。
4. **软删 + 租户**：日常用 `query()`；跨租户用 `withoutTenantScope()`；查已删用 `withoutTrashed()`；两者可按需组合时注意语义（先想清楚过滤目标）。
5. **综合示例**：可参考 `swoolefy/Test/Module/Order/Controller/UserOrderController.php`（Entity CRUD、事务、协程、分页、软删、insertAll 等）。

---

## 快速对照

```php
// Query
Db::table('tbl_order')->where('id', 1)->find();

// Model + 租户
Order::query()->where('user_id', 1)->select();

// 跨租户
Order::withoutTenantScope()->select();

// 含软删
Order::withoutTrashed()->where('order_id', $id)->first();

// 事务
$db->transaction(fn () => /* … */);

// 分页
Order::query()->paginate(15, 1);
Order::query()->paginateX(20, $lastId, 'order_id', 'desc');
```
