# bingcool/library

给 [Swoole](https://github.com/swoole/swoole-src) / [swoolefy](https://github.com/bingcool/swoolefy) 用的 PHP 组件库。命名空间 `Swoolefy\Library`。

覆盖数据库、Redis、队列、HTTP、对象存储、登录、JWT、限流、分布式锁和链路追踪。协程里优先用本库的连接封装，避免 `sleep` / 阻塞 IO 卡住 Worker。

## 环境

| 要求 | 版本 |
|------|------|
| PHP | `>= 8.4` |
| Swoole | 协程运行时（连接池、Channel、`Coroutine::sleep`） |
| 扩展 | `pdo`、`curl`、`json`、`redis`、`openssl` |

Kafka 另需 `ext-rdkafka`。对象存储、OAuth、AMQP 等能力随对应 Composer 依赖启用，不必一次性用全。

```bash
composer require bingcool/library
```

## 模块

详细用法在各模块自己的 README。下表只说明职责和入口。

### 数据

| 模块 | 作用 | 文档 |
|------|------|------|
| `Db` | PDO 连接、Query Builder、Model。支持 MySQL / PostgreSQL / Oracle / SQLite，含事务、软删除、多租户、分页 | [src/Db/README.md](src/Db/README.md) |
| `Mongodb` | MongoDB Model / Collection | — |
| `Redis` | PHPRedis、Predis、RedisCluster 封装 | — |
| `Cache` | `RedisCache`，统一缓存接口 | — |
| `Pool` | 基于 `Swoole\ConnectionPool` 的 Redis / MySQL 连接池 | — |

断线时先恢复连接，再按白名单决定是否重放。只读命令和普通 `SELECT` 最多重放一次；`INCR`、`INSERT`、`UPDATE` 等写操作不重放。见 [Redis / PDO Retry 方案](docs/library-6.x-Redis-PDO-Retry技术方案.md)。

### 消息

| 模块 | 作用 | 文档 |
|------|------|------|
| `Queues` | Redis 即时队列（List）和延迟队列（ZSET） | [src/Queues/README.md](src/Queues/README.md) |
| `Amqp` | RabbitMQ：Direct / Fanout / Topic，以及基于死信的延迟队列 | [src/Amqp/README.md](src/Amqp/README.md) |
| `Kafka` | rdkafka 生产者 / 消费者 | [src/Kafka/README.md](src/Kafka/README.md) |
| `PubSub` | Redis 发布订阅 | — |
| `Aliyun/Datahub` | 阿里云 DataHub | [src/Aliyun/Datahub/README.md](src/Aliyun/Datahub/README.md) |

### HTTP 与外部系统

| 模块 | 作用 | 文档 |
|------|------|------|
| `CurlProxy` | Guzzle Handler：透传 `x-trace-id`、记录请求/响应日志、可选 OpenTelemetry Span。响应日志里的 body 最多保留 1024KB | [src/CurlProxy/Readme.md](src/CurlProxy/Readme.md) |
| `HttpClient` | 轻量 HTTP 响应解析 | — |
| `Nacos` | 配置中心与服务发现，HTTP 走 Guzzle，可接协程连接池 | [src/Nacos/README.md](src/Nacos/README.md) |
| `Oauth` | QQ、微信、支付宝、飞书、钉钉、企业微信登录门面 | [src/Oauth/README.md](src/Oauth/README.md) |
| `FileStorageSystem` | 本地 / S3 / 阿里云 OSS / 腾讯云 COS 统一存储 | [src/FileStorageSystem/README.md](src/FileStorageSystem/README.md) |
| `OpenTelemetry` | 内置 OpenTelemetry PHP API / SDK 与 OTLP 导出 | [src/OpenTelemetry/README.md](src/OpenTelemetry/README.md) |

### 安全与校验

| 模块 | 作用 | 文档 |
|------|------|------|
| `Jwt` | HMAC / RSA / ECDSA 签发、解析与校验 | [src/Jwt/README.md](src/Jwt/README.md) |
| `Encryption` | OpenSSL 对称加解密（CBC / GCM） | [src/Encryption/README.md](src/Encryption/README.md) |
| `Hashing` | Bcrypt / Argon2 密码哈希 | — |
| `Captcha` | 图形验证码 | [src/Captcha/README.md](src/Captcha/README.md) |
| `Validate` | 参数校验（`Swoolefy\Library\Validate`） | — |

### 并发与编号

| 模块 | 作用 | 文档 |
|------|------|------|
| `Lock` | Redis / MySQL / PostgreSQL / 文件锁，协程内 `synchronized` | [src/Lock/README.md](src/Lock/README.md) |
| `RateLimit` | Redis 滑动窗口与令牌桶，Lua 保证原子性 | [src/RateLimit/README.md](src/RateLimit/README.md) |
| `Uuid` | Redis 分布式自增 ID（时间前缀 + 段内序号，不是 UUID v4） | [src/Uuid/README.md](src/Uuid/README.md) |
| `Events` | 事件分发 | — |

`Uuid` 每次生成使用独立的重试次数，失败不会把实例上的默认次数减掉。`generateId()` 失败返回 `null`，调用方不会拿 `null` 去做减法。见 [UUID P1 修复说明](docs/library-6.x-UUID-P1-Bug修复技术方案.md)。

### 工具

| 模块 | 作用 | 文档 |
|------|------|------|
| `Protobuf` | Protobuf 序列化 | — |
| `Helper` / `ArrayHelper` / `Collection` | 字符串、数组、集合工具 | — |
| `Spl` | 数组、Bean、流、双向链表 | [src/Spl/README.md](src/Spl/README.md) |
| `Purl` | URL 解析 | [src/Purl/README.md](src/Purl/README.md) |
| `Clock` | 可替换时钟，便于测试 | — |
| `LinuxDash` | Linux 主机指标采集 | [src/LinuxDash/README.md](src/LinuxDash/README.md) |

## 在 swoolefy 里取组件

业务侧通常不直接 `new`，而是从应用容器拿已经配好的实例：

```php
use Swoolefy\Core\Application;

$db = Application::getApp()->get('db');
$redis = Application::getApp()->get('redis')->getObject();
```

各模块 README 里的示例都按这个方式写。组件名以应用 `Config/component` 为准。

## 测试

```bash
vendor/bin/phpunit --bootstrap tests/bootstrap.php tests
```

示例与集成脚本在 [tests](tests)。需要本机 Redis、MySQL 或 Kafka 的用例不会在默认单测里强连外部服务。

## 许可

[MIT](https://opensource.org/licenses/MIT)
