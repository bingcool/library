# CurlProxy

Guzzle HTTP 客户端的 **HandlerStack 增强层**：在保持原生 Guzzle API 的前提下，自动注入链路追踪头、记录请求/响应日志，并可选接入 OpenTelemetry。

命名空间：`Swoolefy\Library\CurlProxy`

---

## 特性

| 能力 | 说明 |
|------|------|
| 透明接入 | 只需把 `handler` 换成 `CurlProxyHandler::getStackHandler()`，其余按 Guzzle 原样使用 |
| Trace 透传 | 自动注入请求头 `x-trace-id`（来自协程 Context） |
| 请求/响应日志 | 写入 `guzzle_curl` 通道（`LogManager::GUZZLE_CURL_LOG`） |
| OpenTelemetry | Worker 进程且 `OTEL_INSTRUMENTATION_GUZZLE_ENABLED=true` 时开启 Client Span |
| psr7 兼容 | 自定义 `PrepareBodyMiddleware`，避免 `Content-Length` int 在 guzzlehttp/psr7 2.11+ 触发弃用告警 |

---

## 目录结构

```
CurlProxy/
├── CurlProxyHandler.php       # Handler + getStackHandler() 组装中间件栈
├── RequestMiddleware.php      # 注入 Header、记录请求日志
├── ResponseMiddleware.php     # 记录响应日志
├── OpentelemetryMiddleware.php
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
2. **Request：`x-trace-id`** — 从协程 Context 读取并写入请求头  
3. **Request：请求日志** — host / path / method / body / trace_id → `guzzle_curl`  
4. **Response：响应日志** — 原始响应体 → `guzzle_curl`  
5. **OpenTelemetry（可选）** — 仅当：
   - `env('OTEL_INSTRUMENTATION_GUZZLE_ENABLED')` 为真  
   - 当前为 Worker 进程（`Swfy::isWorkerProcess()`）  
   - 当前协程内 Guzzle Span 数未超过上限（默认 100，可用 `OTEL_INSTRUMENTATION_GUZZLE_MAX_TRACE_SPANS_NUM` 调整）

---

## 日志

- 通道：`LogManager::GUZZLE_CURL_LOG`（通常对应 `guzzle_curl.log`）  
- 仅在协程环境（`Coroutine::getCid() >= 0`）且日志通道已注册时写入  
- 请求日志含：host、path、method、trace_id、body、时间  
- 响应日志含：path、trace_id、响应原文  

自定义/排查时可直接用：

```php
$logger = CurlProxyHandler::buildLogChannel();
$logger?->info('custom message');
```

SDK 连接重试等场景也会复用该通道写失败与下一跳信息。

---

## OpenTelemetry 相关环境变量

| 变量 | 说明 |
|------|------|
| `OTEL_INSTRUMENTATION_GUZZLE_ENABLED` | 是否对 Guzzle 出站请求建 Span |
| `OTEL_INSTRUMENTATION_GUZZLE_MAX_TRACE_SPANS_NUM` | 单协程最大 Guzzle Span 数，默认 `100` |
| `OTEL_TRACING_NAME` | Tracer 名称，默认 `swoolefy-http-request` |

Context 键（常量）：

- `OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID` → 请求头 `x-trace-id`  
- `OpentelemetryMiddleware::OPENTELEMETRY_TRACEPARENT_ID` → W3C `traceparent` 传播  

---

## 注意事项

1. **用法与原生 Guzzle 一致**：除 `handler` 外，`json` / `form_params` / `multipart` / `headers` 等均按 Guzzle 文档使用。  
2. **协程友好**：日志与 trace 依赖协程 Context；非协程或未注册 `guzzle_curl` 日志时，自动跳过写日志。  
3. **Body 可重读**：请求/响应中间件读完 body 后会 `rewind()`，一般不影响后续读取。  
4. **Span 上限**：防止单请求内连环 HTTP 打爆追踪；超限后本轮不再 push OTEL 中间件，但 Header / 日志中间件仍生效。  
5. **示例代码**：可参考 `swoolefy/Test/Controller/IndexController.php`、`PgController.php` 中的 Guzzle 用法。

---

## API 速查

```php
// 获取已装配中间件的 HandlerStack
$stack = CurlProxyHandler::getStackHandler();

// 仅替换 prepare_body（高级用法）
CurlProxyHandler::applyPsr7CompatiblePrepareBody($stack);

// 获取 guzzle_curl 日志实例（协程内）
$logger = CurlProxyHandler::buildLogChannel();
```
