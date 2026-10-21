# CurlProxy

Guzzle HTTP 客户端的 **HandlerStack 增强层**：在保持原生 Guzzle API 的前提下，自动注入链路追踪头、记录请求/响应日志，并可选接入 OpenTelemetry。

命名空间：`Swoolefy\Library\CurlProxy`

---

## 特性

| 能力 | 说明 |
|------|------|
| 透明接入 | 只需把 `handler` 换成 `CurlProxyHandler::getStackHandler()`，其余按 Guzzle 原样使用 |
| Trace 透传 | 每次请求从协程 Context 读取 traceId 并注入 `x-trace-id`；调用方自带值在 Context 为空时原样保留 |
| 请求/响应日志 | 写入 `guzzle_curl` 通道（`LogManager::GUZZLE_CURL_LOG`），单条包体最多 512KB |
| 异常日志 | 传输层异常写入 `【response-error@...】`，同时保持 Promise 拒绝语义 |
| OpenTelemetry | Worker 进程且 `OTEL_INSTRUMENTATION_GUZZLE_ENABLED=true` 时创建 Client Span，Span 在 HTTP 完成后结束 |
| 敏感信息保护 | Span 中脱敏 `Authorization`/`Cookie` 等请求头、`password`/`token` 等包体字段，URL 去掉 userinfo |
| 流式安全 | 不可 seek 的请求/响应流不会被读取或 rewind，日志以占位符代替 |
| psr7 兼容 | 自定义 `PrepareBodyMiddleware`，避免 `Content-Length` int 在 guzzlehttp/psr7 2.11+ 触发弃用告警 |

---

## 目录结构

```
CurlProxy/
├── CurlProxyHandler.php       # Handler + getStackHandler() 组装中间件栈
├── RequestMiddleware.php      # 注入 Header、记录请求日志
├── ResponseMiddleware.php     # 记录响应/异常日志
├── OpentelemetryMiddleware.php
├── MessageBodyPreview.php     # 有上限、可回卷的包体预览
├── PrepareBodyMiddleware.php  # psr7 2.11+ Content-Length 兼容
└── README.md
```

---

## 快速开始

```php
use GuzzleHttp\Client;
use Swoolefy\Library\CurlProxy\CurlProxyHandler;

$client = new Client([
    'handler'  => CurlProxyHandler::getStackHandler(),
    'base_uri' => 'http://example.com/',
    'timeout'  => 10,
]);

$response = $client->post('api/staff/test/echo', [
    'json' => [
        'class'   => '一年级',
        'school'  => '希望小学',
    ],
]);

$result = json_decode($response->getBody()->getContents(), true);
```

协程内请保证已设置 trace（若业务网关 / 中间件已写入 Context，一般无需手动设置）：

```php
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;

Context::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, $traceId);
```

---

## Handler 栈做了什么

`CurlProxyHandler::getStackHandler()` 会创建基于本库 Curl Handler 的 `HandlerStack`，并按顺序挂载：

1. **PrepareBody（兼容层）** — `Content-Length` 以 string 写入，避免 psr7 弃用警告
2. **Request：`x-trace-id`** — 每次请求从协程 Context 读取，Context 为空时不覆盖调用方 Header
3. **Request：请求日志** — host / path / query / method / body / trace_id → `guzzle_curl`
4. **Response：响应日志** — 响应体预览（≤512KB）→ `guzzle_curl`；传输异常写 `【response-error@...】`
5. **OpenTelemetry（可选）** — 仅当：
   - `env('OTEL_INSTRUMENTATION_GUZZLE_ENABLED')` 为真
   - 当前为 Worker 进程（`Swfy::isWorkerProcess()`）
   - 当前协程内 Guzzle Span 数未超过上限（默认 100，可用 `OTEL_INSTRUMENTATION_GUZZLE_MAX_TRACE_SPANS_NUM` 调整）

Guzzle 的 `http_errors` 位于整条栈的最外层，因此 4xx/5xx 响应会先经过响应日志，再由 `http_errors` 抛出 `ClientException` / `ServerException`，响应体不会漏记。

---

## 日志

- 通道：`LogManager::GUZZLE_CURL_LOG`（通常对应 `guzzle_curl.log`）
- 仅在协程环境（`Coroutine::getCid() >= 0`）且日志通道已注册时写入
- 请求日志含：host、path（含 query）、method、trace_id、body、时间
- 响应日志含：path、trace_id、响应体预览
- 包体只读取前 **512KB**；超限时追加 `...(truncated, N bytes)`，不会把完整大包体载入内存
- 不可读或不可 seek 的流不会被消费，日志显示 `[body skipped: not readable or not seekable]`
- 日志上下文按请求对象保存，同一协程内并发发起的多个请求不会互相串 path/trace_id

自定义/排查时可直接用：

```php
$logger = CurlProxyHandler::buildLogChannel();
$logger?->info('custom message');
```

> 请求/响应日志按设计保留原始包体（最多 512KB），可能包含业务敏感数据；日志通道的访问权限与采集脱敏需在部署侧治理。Span 属性已内置脱敏。

---

## OpenTelemetry

### 环境变量

| 变量 | 说明 |
|------|------|
| `OTEL_INSTRUMENTATION_GUZZLE_ENABLED` | 是否对 Guzzle 出站请求建 Span |
| `OTEL_INSTRUMENTATION_GUZZLE_MAX_TRACE_SPANS_NUM` | 单协程最大 Guzzle Span 数，默认 `100`；`<=0` 表示关闭 |
| `OTEL_TRACING_NAME` | Tracer 名称，默认 `swoolefy-http-request` |

### Span 行为

- 在请求发出前创建，在响应返回或异常时结束，耗时覆盖真实 HTTP I/O
- 自动设置 `http.response.status_code`；状态码 `>=400` 时标记 `Error`
- 响应带 `Content-Length` 时记录 `http.response.body.size`
- 请求头传播：先移除调用方的 `traceparent`，再按当前 Span 注入；`x-trace-id` 由 Context 提供
- 请求体只在 `application/json` / `application/x-www-form-urlencoded` 且可安全读取时记录，最多 32KB
- 以下字段在 Span 中统一替换为 `[REDACTED]`：
  - Header：`authorization`、`proxy-authorization`、`cookie`、`set-cookie`、`x-api-key`、`x-auth-token`、`x-csrf-token`
  - Body：`password`、`pwd`、`token`、`access_token`、`refresh_token`、`secret`、`client_secret`、`api_key`、`session_id` 等（大小写与 `-`/`_` 写法均匹配）

Context 键（常量）：

- `OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID` → 请求头 `x-trace-id`
- `OpentelemetryMiddleware::OPENTELEMETRY_TRACEPARENT_ID` → W3C `traceparent` 传播

### 自定义请求头属性

```ini
; php.ini
otel.instrumentation.http.request_headers = "x-request-id,x-tenant-id"
```

逗号分隔；命中的请求头会以 `http.request.header.<name>` 写入 Span。

---

## 注意事项

1. **用法与原生 Guzzle 一致**：除 `handler` 外，`json` / `form_params` / `multipart` / `headers` 等均按 Guzzle 文档使用。
2. **协程友好**：日志与 trace 依赖协程 Context；非协程或未注册 `guzzle_curl` 日志时自动跳过写日志。
3. **不消费业务包体**：预览读取后会 rewind；不可 seek 的流直接跳过，流式上传/下载不受影响。
4. **Span 上限按协程计**：防止单请求内连环 HTTP 产生海量 Span；超限后仅跳过 OTel，Header 与日志中间件仍生效。
5. **`http.response.body.size` 依赖 `Content-Length`**：chunked 响应或流式 sink 无法在不读取包体的前提下获知大小，此时不写该属性。
6. **示例代码**：可参考 `swoolefy/Test/Controller/IndexController.php`、`PgController.php` 中的 Guzzle 用法。

---

## 测试

```bash
vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/CurlProxy
```

测试覆盖：包体预览截断与 UTF-8 安全、不可 seek 流、traceId 按请求读取、并发请求日志上下文隔离、响应异常透传、Span 生命周期/状态/脱敏/traceparent 注入/预算。

---

## API 速查

```php
// 获取已装配中间件的 HandlerStack
$stack = CurlProxyHandler::getStackHandler();

// 仅替换 prepare_body（高级用法）
CurlProxyHandler::applyPsr7CompatiblePrepareBody($stack);

// 获取 guzzle_curl 日志实例（协程内）
$logger = CurlProxyHandler::buildLogChannel();

// 读取包体前 N 字节（不消费业务流）
$preview = MessageBodyPreview::read($stream, 64 * 1024);
```
