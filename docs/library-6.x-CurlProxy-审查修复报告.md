# library-6.x CurlProxy 模块审查修复报告

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块 | CurlProxy（`src/CurlProxy/**/*.php`）及 OTel 依赖链（`src/OpenTelemetry/**` 的部分命名空间修复） |
| 审查日期 | 2026-10-21 |
| 审查范围 | `CurlProxyHandler`、`RequestMiddleware`、`ResponseMiddleware`、`OpentelemetryMiddleware`、`PrepareBodyMiddleware`、新增 `MessageBodyPreview`、`README.md`；`tests/CurlProxy/**`；OTel 自动加载所依赖的 fork 类 |
| 关联依赖 | `guzzlehttp/guzzle` 7.9.x-dev、`guzzlehttp/psr7` 2.13.x-dev、Swoole 6.2.2、swoolefy `Context` / `LogManager` / `Swfy` / `SystemEnv` |
| 运行环境 | 容器 `swoolefy-php85-v62`：PHP 8.5.8、Swoole 6.2.2、PHPUnit 11.5.57；代码目录 `/home/wwwroot/library`，框架目录 `/home/wwwroot/swoolefy`，`swoolefy/vendor/bingcool/library` 指向本仓库 |
| 审查方式 | 逐文件通读 + `git show HEAD` 修复前代码对照 + Guzzle 7.9 vendor 语义核对 + 容器内定向复现 + 单测 + swoolefy `Test` 服务 worker 端到端验证 + OTLP JSON 抓包解码 |
| 测试基线 | 修复后 `tests/CurlProxy` 共 26 个用例 / 73 个断言，容器内全绿；worker 端 `/curlproxy/verify` 全项通过 |
| 源码变更 | `CurlProxy` 5 个文件重写/调整，新增 `MessageBodyPreview.php`；OTel fork 修复 7 处命名空间；新增 4 个测试文件，删除失效的 `ResponseMiddlewareLogLimitTest.php`；重写 `src/CurlProxy/README.md`。未提交 |

**结论摘要**

- **P0：4 项**。均会造成业务请求失败、包体丢失或 worker 进程不稳定：
  1. 日志/包体预览全量读取，80MB 响应在 64M `memory_limit` 下直接 OOM；
  2. OTel 构建属性时消耗请求体，form-urlencoded 还调用了 PSR-7 不存在的 `getParsedBody()`，请求体变空或直接 Error；
  3. 不可 seek 的请求/响应流被无条件 `rewind()`，流式上传/下载抛异常；
  4. OTel fork 的自动加载存在多处错误命名空间，`OTEL_PHP_AUTOLOAD_ENABLED=true` 时 worker 无法启动。
- **P1：6 项**。Span 在 HTTP 发起前就被 `end()`、Context attach 不 detach 导致链路污染、traceId 在 HandlerStack 构建期被固定、Span 预算在构建期计数、敏感 Header/包体进入 Span、日志控制字符可注入。
- **P2：5 项**。传输异常无响应日志、进程判断/`env()` 无守卫、OTel 属性语义与配置解析错误、同协程并发请求日志上下文串值、缺少自动化测试且 README 与实现不符。
- 未发现鉴权绕过、代码执行、SQL/文件注入类 P0 安全缺陷；敏感信息进入 Span 属于可观测性侧的凭据泄露面，按 P1 处理。
- **已否证**：`4xx/5xx 不记录响应日志` 不成立。响应日志位于 `http_errors` 内层，500 响应会先被记录再抛 `ServerException`，worker E2E 中 `has_500_body=true`。
- 修复后单测 26/26、worker E2E 全项通过，OTLP 抓包确认 Span 耗时覆盖真实 HTTP I/O、500 标记 Error、traceparent 正确传播且不污染当前 Context。

---

## 2. 问题总览

| 等级 | ID | 问题 | 位置（修复后） | 核实结果 | 状态 |
|---|---|---|---|---|---|
| P0 | CP-01 | 请求/响应日志全量 `getContents()`，上限截断发生在完整分配之后；80MB 包体可 OOM；无日志时也全量读请求体 | `RequestMiddleware.php:58`、`ResponseMiddleware.php:37`；修复前 `git show HEAD` 同文件 | 已复现 | 已修复 |
| P0 | CP-02 | OTel 读取 JSON 包体后不回卷，下游发送空体；form 请求调用 `RequestInterface::getParsedBody()` 直接 Error | `OpentelemetryMiddleware.php:260`；修复前 `:handleRequestBody` | 已复现 | 已修复 |
| P0 | CP-03 | 不可 seek 的请求/响应流被无条件 `rewind()`，流式上传/下载抛 `RuntimeException` | `RequestMiddleware.php:58`、`ResponseMiddleware.php:37`；修复前同位置 | 已复现 | 已修复 |
| P0 | CP-04 | OTel fork 自动加载使用 vendor 命名空间：`SdkAutoloader`、`EnvResolver`、`EnvComponentLoaderRegistry`、`OtlpUtil`、`MeterProviderInterface`、`Meter`、`AsynchronousInstruments` | `SdkAutoloader.php:190` 等 7 处 | 已复现（autoload fatal） | 已修复 |
| P1 | CP-05 | Client Span 在发起 HTTP 前 `end()`，耗时恒为 0；响应状态/异常未记录 | `OpentelemetryMiddleware.php:89-150`、`:400` | 已复现 | 已修复 |
| P1 | CP-06 | `storage()->attach($context)` 后未 detach，已结束的 Client Span 成为当前 Span，污染后续链路 | 修复前 `opentelemetryStartTrace()` 尾部；`OpentelemetryMiddleware.php:189-190` | 已复现 | 已修复 |
| P1 | CP-07 | traceId 在 `getStackHandler()` 构建时读取并固定，跨协程串值；空值覆盖调用方 `x-trace-id` | `CurlProxyHandler.php:93-94`、`RequestMiddleware.php:39` | 已复现 | 已修复 |
| P1 | CP-08 | Span 预算在构建 HandlerStack 时计数，栈复用后上限失效；非协程构建时 `Context::set` 抛异常 | `OpentelemetryMiddleware.php:373`；`CurlProxyHandler.php:100` | 已复现 | 已修复 |
| P1 | CP-09 | Span 记录 `Authorization`/`Cookie` 等 Header、`password`/`token` 等包体字段及 URL userinfo | `OpentelemetryMiddleware.php:49-78`、`:213`、`:260` | 已复现 | 已修复 |
| P1 | CP-10 | traceId/path/query/错误消息未清洗控制字符、无长度上限，可伪造日志行 | `RequestMiddleware.php:156`、`:179`；`ResponseMiddleware.php:89` | 已复现 | 已修复 |
| P2 | CP-11 | Promise 拒绝路径无响应日志：超时、连接失败、SSL 错误全部漏记 | `ResponseMiddleware.php:37-77`、`:104` | 已复现 | 已修复 |
| P2 | CP-12 | `env()` 与 `Swfy::isWorkerProcess()` 在构建栈时无守卫，非 swoolefy/非 worker 环境抛异常 | `CurlProxyHandler.php:113` | 已复现 | 已修复 |
| P2 | CP-13 | OTel 属性语义错误：hostname 当客户端地址、body size 用字符串、空 query/port 也写入、配置头 `(array)` 解析错误、Header 属性重复写入 | `OpentelemetryMiddleware.php:213`、`:339` | 已确认 | 已修复 |
| P2 | CP-14 | 同协程并发请求共用单个 `GUZZLE_CURL_PATH` 上下文键，响应日志 path/traceId 互相覆盖 | `RequestMiddleware.php:114`、`ResponseMiddleware.php:79` | 已复现（代码推演 + 新增用例） | 已修复 |
| P2 | CP-15 | `tests/CurlProxy` 基本无自动化用例；README 描述与实现不符；存在死代码/无效预创建文件 | 全模块 | 已确认 | 已修复 |

---

## 3. P0 问题详情

### CP-01 日志全量读取包体导致 OOM

**修复前代码**

```php
// RequestMiddleware::requestRecordLog()
$body = $request->getBody()->getContents();   // 先读完整包体，再判断 logger
...
$logger = CurlProxyHandler::buildLogChannel();
if ($logger) { ... }
$request->getBody()->rewind();

// ResponseMiddleware::responseRecordLog()
$result = $response->getBody()->getContents(); // 先完整读入内存
...
$loggedResult = self::limitLogResult($result); // 512KB 截断发生在分配之后
```

**根因**

- 响应侧虽然已有 `MAX_RESPONSE_LOG_BYTES = 512KB`，但 `getContents()` 已经把完整包体变成 PHP 字符串，截断只是减少落盘字节，无法降低内存峰值。
- 请求侧连 512KB 上限都没有；且读取发生在 `buildLogChannel()` 之前，即使日志通道未注册也会完整读取并 rewind，大文件上传会被整段载入内存。
- 下载、导出、文件上传等场景的包体可达数十至数百 MB，worker 进程可能直接触发 `Allowed memory size exhausted`。

**复现/验证**

- 修复前：80MB 响应在 `memory_limit=64M` 下走 `getContents()` 必然 fatal。
- 修复后：同一 80MB 响应 `peak=10MB`；80MB 请求体 `peak=8MB`；1MB 响应日志出现 `...(truncated, 1048576 bytes)`，worker 日志 7 请求 + 7 响应共 527,633 字节。

**修复**

- 新增 `MessageBodyPreview::read()`：按 8KB 分块，只读取 `maxBytes + 1`，`finally` 中回卷；不可读/不可 seek 返回 `null`，绝不消费业务流。
- `RequestMiddleware::requestRecordLog()` 与 `ResponseMiddleware::responseRecordLog()` 都先判断 logger，再读取预览；上限统一 512KB。
- `MessageBodyPreview::truncate()` 按字节截断并用 `mb_strcut` 收口，保证 UTF-8 不被切断，结果不超过上限。
- 回归用例：`MessageBodyPreviewTest` 9 个用例，覆盖精确上限、未知总长、UTF-8、非 seek 流、零上限等。

---

### CP-02 OTel 读取请求体导致包体丢失 / form 请求 Error

**修复前代码**

```php
private static function handleRequestBody(RequestInterface $request)
{
    $contentType = $request->getHeaderLine('Content-Type');
    $postData = [];

    if (str_contains($contentType, 'application/json')) {
        $postData = json_decode((string) $request->getBody(), true); // 读后不回卷
    } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
        $postData = $request->getParsedBody(); // PSR-7 Request 没有此方法
    }

    return json_encode($postData, JSON_UNESCAPED_UNICODE);
}
```

**根因**

- `RequestInterface` 只有 `getBody()`，`getParsedBody()` 是 PSR-7 `ServerRequestInterface` 的方法；对 Guzzle 的 `Request` 调用会抛 `Error: Call to undefined method GuzzleHttp\Psr7\Request::getParsedBody()`。
- JSON 分支用 `(string)$request->getBody()` 把流读到 EOF，且没有 `rewind()`；后续 `CurlFactory` 读到空流，服务端收到空包体，而 `Content-Length` 仍是原值。
- 该中间件在请求链中位于 `CurlFactory` 之前，因此问题直接作用于真实出站请求，而不是仅影响日志。

**复现/验证**

- 修复前定向复现：form 请求抛出上述 `Error`；JSON 请求下游中间件看到的 body 为空。
- 修复后：form 请求 `user=abc&password=secret` 原样发送，Span 中记录 `{"user":"abc","password":"[REDACTED]"}`；JSON 请求下游 body 完整。
- 用例：`testFormBodyIsRecordedRedactedAndLeftIntact`、`testJsonBodyIsRecordedRedactedAndLeftIntact`。

**修复**

- `previewRequestBody()` 统一使用 `MessageBodyPreview::read($request->getBody(), 32KB)`，读取后回卷。
- form-urlencoded 改用 `parse_str()`，不再调用 `getParsedBody()`。
- 只对 JSON / form 两种 Content-Type 记录包体；解析失败返回 `null`，不影响请求。
- 读取或解析抛异常时由上层 `opentelemetryStartTrace()` 捕获，降级为不建 Span，不阻塞请求。

---

### CP-03 不可 seek 流被强制 rewind

**修复前代码**

```php
// RequestMiddleware::requestRecordLog()
} catch (Throwable $exception) {
}
$request->getBody()->rewind(); // try/catch 之外，且不判断 isSeekable()

// ResponseMiddleware::responseRecordLog()
$response->getBody()->rewind(); // 同样无判断
```

**根因**

- `NoSeekStream::rewind()` 会抛 `RuntimeException`；Guzzle 的流式上传、显式 `sink` 为非 seek 流时，日志中间件反而让请求/响应失败。
- 请求侧 `rewind()` 在 try/catch 之外，异常直接冒泡；响应侧 `getContents()` 已消费流，后面的 `rewind()` 再抛异常。
- 旧实现默认“所有流都可重读”，与 PSR-7 `StreamInterface::isSeekable()` 契约不符。

**复现/验证**

- 修复前：`NoSeekStream` 作为请求体或 `sink` 时抛 `Cannot seek a non-seekable stream`。
- 修复后 worker E2E：非 seek 请求体服务端收到 `raw-body`；非 seek `sink` 返回 200、写入 228 字节；请求/响应日志均记录 `[body skipped: not readable or not seekable]`。
- 用例：`testRequestLogDoesNotConsumeNonSeekableBody`、`testNonSeekableResponseBodyIsLeftUntouched`。

**修复**

- `MessageBodyPreview::read()` 在读取前检查 `isReadable() && isSeekable()`，不满足直接返回 `null`，不读、不 rewind。
- 中间件层不再自己调用 `rewind()`；可 seek 流由预览函数在 `finally` 中回卷。
- 非 seek 流在日志中写固定占位符 `[body skipped: not readable or not seekable]`。

---

### CP-04 OTel fork 自动加载致命错误

**问题位置**

| 文件 | 修复内容 |
|---|---|
| `src/OpenTelemetry/SDK/SdkAutoloader.php:190` | `new \OpenTelemetry\API\Configuration\Context()` → fork 命名空间 |
| `src/OpenTelemetry/SDK/Common/Configuration/EnvResolver.php:12` | 实现 fork 的 `EnvResolver` 接口 |
| `src/OpenTelemetry/SDK/Common/Configuration/EnvComponentLoaderRegistry.php:24` | 实现 fork 的 `EnvComponentLoaderRegistry` 接口 |
| `src/OpenTelemetry/SDK/Metrics/MeterProviderInterface.php:9` | 继承 fork 的 `MeterProviderInterface` |
| `src/OpenTelemetry/SDK/Metrics/Meter.php:27` | `use function` 改为 fork 的 `closure` |
| `src/OpenTelemetry/SDK/Metrics/AsynchronousInstruments.php:9-10` | `use function` 改为 fork 的 `closure` / `weaken` |
| `src/OpenTelemetry/Contrib/Otlp/OtlpUtil.php:12` | `ResourceAttributes` 改为 fork 命名空间 |

**根因**

- 该仓库把 OpenTelemetry 整体复制到 `Swoolefy\Library\OpenTelemetry` 下，但部分类仍指向全局 `OpenTelemetry\...`。当 Composer 未安装同版本上游包时，`class_exists` 失败或接口不匹配，`SdkAutoloader::autoload()` 在 `OTEL_PHP_AUTOLOAD_ENABLED=true` 时抛致命错误，worker 启动阶段即失败。
- `src/OpenTelemetry/SDK/_autoload.php` 被 `composer.json` 的 `autoload.files` 无条件加载，因此只要开启 OTel 自动加载就会触发。

**验证**

- 修复前：加载 `Swoolefy\Library\OpenTelemetry\SDK\Trace\TracerProvider` 或实例化 Span 时 fatal。
- 修复后：容器内 autoload smoke test 成功创建 Provider/Span；worker 以 `OTEL_PHP_AUTOLOAD_ENABLED=true` 启动并成功导出 8 条 Span（7 条 client + 1 条 server）。

---

## 4. P1 问题详情

### CP-05 Span 提前结束，耗时恒为 0

**修复前**：`opentelemetryStartTrace()` 在注入 `traceparent` 后立即 `$span->end()`；`opentelemetryEndTrace()` 只 `return $response`，不结束 Span，也不记录状态。

**影响**

- 所有 Client Span 的耗时为 0，无法定位慢请求。
- 4xx/5xx 与连接异常在 Span 上没有 Error 状态，告警与链路分析全部失真。

**修复**

- 用 `WeakMap<RequestInterface, SpanInterface>` 保存 Span：`start` 中间件创建并登记，`end` 中间件在 `then()` 的 fulfilled/rejected 分支结束；同步 throw 也走 `finishSpan()`。
- `finishSpan()` 设置 `http.response.status_code`、可选 `http.response.body.size`，`>=400` 标记 `STATUS_ERROR`；异常调用 `recordException()` 并标记 Error；`finally` 保证 `end()`。
- 用例 `testSpanEndsAfterHttpCall` 用 120ms 终端验证 Span ≥100ms；worker 中 POST 请求 Span 1003.515ms，证明覆盖真实 I/O。

### CP-06 Context 泄漏

**修复前**：

```php
$scope   = $span->activate();
$context = $span->storeInContext($parentContext);
$propagator->inject($request, HeadersPropagator::instance(), $context);
OpenTelemetryContext::storage()->attach($context); // 只 attach，不 detach
$span->end();
$scope->detach();
```

**影响**：已结束的 Client Span 成为当前 Context，后续业务代码/请求把它当父 Span，链路层级错误；`scope` 与 storage 的当前值不一致，后续 `Span::getCurrent()` 结果不可预期。

**修复**

- 不再调用 `storage()->attach()`；只用 `storeInContext()` 构造传播上下文并注入 `traceparent`。
- 用例 `testClientSpanDoesNotLeakIntoCurrentContext`：连续两次请求后 `Span::getCurrent()` 仍是原 root，且两条 Client Span 的 `parentSpanId` 均指向 root。
- worker E2E：主协程的 Client Span 父级为 server span；子协程未传播 `traceparent` 时生成独立 root Span，不再错误继承其他协程的 Span。

### CP-07 traceId 在构建 HandlerStack 时固定

**修复前**：

```php
if (SwooleContext::has(OPENTELEMETRY_X_TRACE_ID)) {
    $traceId = SwooleContext::get(OPENTELEMETRY_X_TRACE_ID);
}
$stack->push(RequestMiddleware::addHeader(OPENTELEMETRY_X_TRACE_ID, $traceId ?? ''));
```

**影响**

- HandlerStack 通常被 Worker 内多个协程复用，构建时读到的 traceId 会永久串到其他请求。
- Context 为空时仍然注入空字符串，覆盖调用方自带的 `x-trace-id`。
- 构建之后才写入 Context 的 traceId 永远不会生效。

**修复**

- 删除 `RequestMiddleware::addHeader()`，改为 `addTraceIdHeader()`：每次请求执行时读取 Context，非空才 `withHeader()`。
- `currentTraceId()` 去掉 `\x00-\x20\x7F` 控制字符，长度限制 128；Context 不可用时返回空串。
- 用例：`testTraceIdHeaderIsReadPerRequest`（trace-one/trace-two）、`testEmptyContextDoesNotOverwriteCallerHeader`、`testControlCharactersAreStrippedFromTraceId`。

### CP-08 Span 预算在构建期计数

**修复前**：`getStackHandler()` 内对 `SwooleContext::has/set(__HTTP_CURL_MAX_SPAN_TRACE)` 计数并在超限时直接返回未装配 OTel 的栈。

**影响**

- 栈通常只构建一次，预算检查也只发生一次；同一协程后续请求不再受限，连环 HTTP 仍可产生海量 Span。
- 在 Worker 启动阶段/非协程环境构建客户端时，`SwooleContext::set()` 可能抛 `ContextUnavailableException`。
- 预算状态挂在协程 Context 上，但计数发生在构建期，语义上既不是“每请求”也不是“每协程调用”。

**修复**

- 预算检查移入 `opentelemetryStartTrace()` 的每次请求；`SPAN_COUNT_KEY` 按协程计数，默认 100，`<=0` 关闭。
- `consumeSpanBudget()` 捕获 Context 异常并默认放行，避免观测逻辑影响业务。
- 用例 `testSpanBudgetIsEnforcedPerRequest`：上限 2 时连续 3 次请求只产生 2 条 Span。

### CP-09 敏感信息进入 Span

**修复前**：`HTTP_REQUEST_HEADERS` 直接 `json_encode($request->getHeaders())`，包含 `Authorization`、`Cookie`；JSON/form 包体原样写入 `http.request.body`；`URL_FULL` 使用 `(string)$uri`，带 userinfo；Header 属性还在注入 `traceparent` 前后各写一次，内容不一致。

**修复**

- Header 脱敏：`authorization`、`proxy-authorization`、`cookie`、`set-cookie`、`x-api-key`、`x-auth-token`、`x-csrf-token` 写 `[REDACTED]`。
- Body 脱敏：递归遍历 JSON/form 数据，键名统一去除非字母数字后匹配 `password`、`pwd`、`token`、`accesstoken`、`clientsecret`、`apikey`、`sessionid` 等；camelCase 与 `-`/`_` 写法均可命中。
- `URL_FULL` 使用 `(string)$uri->withUserInfo('')`。
- Header 属性只在注入 `traceparent` 后写一次，并经过 `sanitizeHeaders()`。
- 用例覆盖 form/JSON 的 `access_token`、`accessToken`、`client_secret`、`ClientSecret`，断言原文不出现在 Span 属性中。

> 请求/响应**日志**仍按调试目的保留原始包体（最多 512KB），未做字段脱敏；这是日志使用面的治理问题，已在 README 中明确提示。

### CP-10 日志控制字符注入与字段无界

**修复前**：traceId 直接进入 Header 与日志前缀；path 通过 `urldecode(query)` 后原样进入日志；错误消息直接拼接。攻击者可通过带换行的 traceId/query 伪造日志行；超长字段可撑大单条日志。

**修复**

- `currentTraceId()` 去除 `\x00-\x20\x7F` 并限制 128 字节。
- `sanitizeLogValue()` 将 `\x00-\x1F\x7F` 替换为空格，限制 2048 字节并追加 `...`。
- path/query/traceId/异常消息统一走清洗函数。
- 用例 `testControlCharactersAreStrippedFromTraceId` 验证换行、制表符被移除。

---

## 5. P2 问题详情

### CP-11 传输异常无响应日志

**现象**：修复前响应日志只挂 `then($fn)`，Promise 拒绝（超时、连接失败、SSL 错误、too many redirects 等）不会进入回调，日志中只有请求记录没有响应记录。

**修复**：`responseRecordLog()` 增加 `onRejected`，写 `【response-error@...】 api=..., traceId=..., 异常：...`（`warning` 级），随后 `Create::rejectionFor($reason)` 保持原拒绝原因。用例 `testRejectionReasonIsPropagated` 验证原异常对象不变。

> 注意：Guzzle 的 `http_errors` 在栈最外层，4xx/5xx 响应在响应日志执行时仍是 fulfilled，因此**会正常记录响应体**；只有传输层拒绝才会走 `onRejected`。

### CP-12 进程判断与环境函数无守卫

**现象**：修复前 `getStackHandler()` 直接调用 `env(...) && Swfy::isWorkerProcess()`；在库单测、CLI 脚本或非 swoolefy 环境，`env()` 可能未定义，`Swfy::isWorkerProcess()` 在无 Server 时抛 `SystemException`，构建客户端即失败。

**修复**：`isOpentelemetryEnabled()` 先 `function_exists('env')`，再 `try/catch` 包裹 `Swfy::isWorkerProcess()`，异常返回 `false`。OTel 关闭时仍保留 Header 与日志能力。

### CP-13 OTel 属性语义与配置解析错误

| 问题 | 修复 |
|---|---|
| `HTTP_CLIENT_HOST` / `CLIENT_ADDRESS` 写 `gethostname()`，语义错误 | 删除这两个属性 |
| `HTTP_REQUEST_BODY_SIZE` 写 `Content-Length` 字符串，缺失时写空串 | 优先 `getSize()`，其次数字型 Header，最后不写；写 int |
| query 为空、port 为 null 时仍写属性 | 仅在非空/非 null 时写 |
| `(array)get_cfg_var('otel.instrumentation.http.request_headers')` 把逗号串当成单元素数组 | `explode(',', ...)` 并逐项 trim |
| Header 属性在注入前后重复写、内容不一致 | 仅在注入 `traceparent` 后写一次 |
| `SpanKind;;` 重复分号 import | 清理 |
| 协程 ID 在非协程下为 -1 | `cid >= 0` 才写 |

### CP-14 同协程并发请求日志上下文串值

**现象**：请求日志把 path/trace_id 写入单个 Context 键 `__guzzle_curl_path`；同一协程内用 `getAsync()` 并发发起 A、B 两个请求时，B 会覆盖 A，A 的响应日志可能打印 B 的 path/trace_id。跨协程场景因 Context 隔离不受影响，因此常规并发测试容易漏掉。

**修复**：

- `RequestMiddleware` 改为 `WeakMap<RequestInterface, array{path, trace_id}>`，按请求对象存储。
- `ResponseMiddleware::mapResponse()` 把 `$request` 传给 fulfilled/rejected 回调，响应日志用同一个 Request 对象取回自己的信息。
- 用例 `testRequestInfoIsScopedPerRequestObject` 验证两个请求对象各自保留 path/trace_id；`testMapResponsePassesRequestToCallbacks` 验证回调拿到原 Request。

### CP-15 测试、文档与死代码

**修复前**：

- `tests/CurlProxy` 只有 `ResponseMiddlewareLogLimitTest`，覆盖一个即将被替换的内部方法；请求中间件、OTel 生命周期、脱敏、预算均无用例。
- README 写“读完 body 后会 rewind()”“非 Worker 进程开启 OTel”等与实现不符的内容；目录结构缺少 `MessageBodyPreview`。
- `RequestMiddleware::addHeader()` 在改为按请求注入后失效；`buildLogChannel()` 用 `fopen()` 预创建日志文件并把句柄丢弃，还写了一个无人读取的 Context 标记。
- OTel Span 预算常量/状态从 Handler 移到中间件后，旧字段成为死代码。

**修复**：

- 新增 4 个测试文件，共 26 用例 / 73 断言；删除失效的 `ResponseMiddlewareLogLimitTest.php`。
- 删除 `addHeader()`、Handler 中的预算常量/状态、`fopen` 预创建逻辑与无用 Context 标记。
- `buildLogChannel()` 增加 `?\Swoolefy\Util\Log` 返回类型。
- 重写 `src/CurlProxy/README.md`，补充 512KB 上限、非 seek 流、异常日志、Span 脱敏、预算语义与测试命令。

---

## 6. 已否证 / 非问题

| 候选问题 | 结论 |
|---|---|
| “4xx/5xx 响应不会被 CurlProxy 记录” | **不成立**。`HandlerStack::create()` 先把 `http_errors` 压入栈，后压入的 CurlProxy 中间件位于其内层；响应日志先执行，`http_errors` 后抛异常。worker E2E 中 500 响应体 `server-error-body` 已记录，`response_entries=7`、`has_500_body=true` |
| “响应日志的 `rewind()` 会破坏正常响应” | **不成立**。Guzzle 默认 `sink` 为 `php://temp`，可 seek；只有显式非 seek `sink` 才会触发，已按 CP-03 修复 |
| “1MB 响应缺少 `http.response.body.size` 是缺陷” | **不是**。审计 HTTP 服务的 `/big` 不返回 `Content-Length`（chunked/流式），不读取包体无法得知大小；正常带 `Content-Length` 的响应会写入该属性 |
| “`CurlProxyHandler::__invoke` 中 `curl_exec` 会阻塞 Worker” | **不成立**。swoolefy 启用 `SWOOLE_HOOK_ALL`，原生 cURL 已被 hook 为协程调度；worker 并发 E2E 正常 |
| “非 Worker 环境没有日志是 CurlProxy 缺陷” | **不是**。日志依赖 swoolefy `LogManager` 与 worker/coroutine 上下文；独立 CLI 下无日志属于框架前提，已在 README 说明 |
| “日志文件不存在时需要通过 `fopen` 预创建” | **不需要**。`Log` 的 `StreamHandler` 会自行创建目录/文件；删除预创建后 worker E2E 日志正常 |

---

## 7. 修复路线与落地记录

按风险从高到低分五步落地，避免“观测逻辑影响业务”：

1. **包体安全（CP-01/02/03）**
   - 新增 `MessageBodyPreview`，统一“有上限、可回卷、非 seek 不消费”的读取语义。
   - 请求/响应日志先判断 logger 再读包体；OTel 请求体预览复用同一组件。
2. **Span 生命周期与上下文（CP-05/06/08）**
   - `WeakMap` 关联 Request 与 Span；`end` 中间件负责 fulfilled/rejected/同步异常三条路径。
   - 去掉 `storage()->attach()`，只做 `storeInContext()` + `traceparent` 注入。
   - 预算从构建期移到每次请求，按协程 Context 计数。
3. **请求头与 traceId（CP-07/10）**
   - 删除固定值 `addHeader()`，改为按请求读取；空值不覆盖调用方 Header；控制字符与长度限制。
4. **可观测性数据质量（CP-09/11/13/14）**
   - Header/Body 脱敏、URL 去 userinfo、属性语义修正、配置头解析修正。
   - 传输异常日志；日志上下文按 Request 对象隔离。
5. **依赖链、测试与文档（CP-04/12/15）**
   - 修复 OTel fork 7 处命名空间，使 `OTEL_PHP_AUTOLOAD_ENABLED=true` 可启动。
   - 守卫 `env()`/`Swfy::isWorkerProcess()`。
   - 新增单测、worker E2E 验证入口与 README。

**变更文件**

| 文件 | 变化 |
|---|---|
| `src/CurlProxy/CurlProxyHandler.php` | 按请求装配 trace/OTel；守卫进程判断；删除预算状态与日志文件预创建 |
| `src/CurlProxy/RequestMiddleware.php` | 新增按请求 trace 注入、512KB 预览、WeakMap 请求上下文、字段清洗 |
| `src/CurlProxy/ResponseMiddleware.php` | 512KB 预览、非 seek 跳过、异常日志、Request 级上下文、回调传 Request |
| `src/CurlProxy/OpentelemetryMiddleware.php` | Span 生命周期重写、WeakMap、预算/脱敏/属性修正、traceparent 注入 |
| `src/CurlProxy/MessageBodyPreview.php` | 新增：分块预览与 UTF-8 安全截断 |
| `src/OpenTelemetry/**`（7 个文件） | fork 命名空间/接口/函数导入修正 |
| `tests/CurlProxy/*.php` | 新增 4 个测试文件，删除 1 个失效测试 |
| `tests/bootstrap.php` | 可选加载同级 swoolefy autoload；提供 `env()` 兜底 |
| `src/CurlProxy/README.md` | 按当前实现重写 |

**接口变化**

- 删除 `RequestMiddleware::addHeader()`（由 `addTraceIdHeader()` 取代）。
- 删除 `ResponseMiddleware::limitLogResult()`（由 `MessageBodyPreview::truncate()` 取代）。
- `ResponseMiddleware::mapResponse()` 回调新增第二个参数 `$request`；只接收一个参数的回调仍可正常工作。
- `CurlProxyHandler::buildLogChannel()` 显式返回 `?\Swoolefy\Util\Log`。

---

## 8. 验证记录

### 8.1 单元测试

```bash
docker exec swoolefy-php85-v62 sh -lc \
  'cd /home/wwwroot/library && vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/CurlProxy'
```

结果：`OK (26 tests, 73 assertions)`。

覆盖点：

- `MessageBodyPreviewTest`：起始位置回卷、精确上限、已知/未知总长截断、非 seek 不消费、不可读返回 null、UTF-8 安全、零上限、小上限。
- `RequestMiddlewareTest`：traceId 按请求读取、空 Context 保留调用方 Header、控制字符清洗、非 seek 请求体不被消费、请求上下文按对象隔离。
- `ResponseMiddlewareTest`：非 seek 响应体不被消费、拒绝原因原样透传、`mapResponse` 链保持、回调收到 Request。
- `OpentelemetryMiddlewareTest`：Span 在 HTTP 后结束（≥100ms）、500 状态与 Error、拒绝记录异常、form/JSON 包体完整且脱敏、traceparent 注入与替换、无 Context 泄漏、预算 2/3。

### 8.2 定向复现（修复前/后）

| 场景 | 修复前 | 修复后 |
|---|---|---|
| Context 为空 + 调用方 `x-trace-id: caller-owned` | 被空值覆盖 | 保留 `caller-owned` |
| 先建栈、后写 traceId | 请求头为空 | 使用 `late-trace-id` |
| form-urlencoded + OTel | `Call to undefined method ...::getParsedBody()` | 正常发送，Span 记录脱敏表单 |
| JSON + OTel | 下游 body 为空 | 下游 body 完整，Span 记录脱敏 JSON |
| 非 seek 请求体 | `rewind()` 抛异常 | 服务端收到 `raw-body` |
| 非 seek sink | `rewind()` 抛异常 | 200，写入 228 字节 |
| 80MB 响应日志（64M 限制） | 全量分配，OOM | `peak=10MB` |
| 80MB 请求日志（64M 限制） | 全量分配，OOM | `peak=8MB` |
| Context 当前 Span | 被已结束的 Client Span 污染 | 保持原 root |

### 8.3 swoolefy Test 服务 worker E2E

启动审计 HTTP 服务与 Test 服务后访问 `GET /curlproxy/verify`，返回：

```json
{
  "framework_trace_id": "d577396a4d412538607f078d0e322954",
  "echoed_trace_id": "d577396a4d412538607f078d0e322954",
  "trace_isolation": [
    {"expected": "trace-a", "actual": "trace-a"},
    {"expected": "trace-b", "actual": "trace-b"}
  ],
  "status500_exception": "GuzzleHttp\\Exception\\ServerException",
  "non_seekable_request_body": "raw-body",
  "non_seekable_sink": {"status": 200, "bytes": 228},
  "big_response_bytes": 1048576,
  "log": {
    "request_entries": 7,
    "response_entries": 7,
    "has_500_body": true,
    "placeholder_entries": 2,
    "truncated_1mb": true,
    "trace_a_entries": 2,
    "trace_b_entries": 2,
    "log_bytes": 527633
  }
}
```

结论：

- 框架 traceId 正确注入并回显；两个并发协程的 traceId 互不串值。
- 500 响应抛 `ServerException`，但响应体已记录（`has_500_body=true`）。
- 非 seek 请求体与非 seek sink 均正常。
- 1MB 响应日志被截断并标记；两条非 seek 场景写入占位符。
- 日志按请求隔离，`trace-a`/`trace-b` 各有 2 条记录。

### 8.4 OTLP 抓包

在容器内用 `php -S 127.0.0.1:4318` 接收 `OTEL_EXPORTER_OTLP_ENDPOINT` 的 JSON 导出，解码结果：

| Span | 耗时 | 状态/属性 |
|---|---|---|
| `HTTP GET /echo (client)`（主请求） | 5.793ms | 200，parent=server span |
| `HTTP GET /echo (client)`（trace-a） | 3.216ms | 200，独立 root（子协程未传播 traceparent） |
| `HTTP GET /echo (client)`（trace-b） | 3.426ms | 200，独立 root |
| `HTTP GET /status500 (client)` | 1.816ms | 500，`status={"message":"HTTP status 500","code":2}` |
| `HTTP POST /echo (client)` | 1003.515ms | 200，覆盖真实 I/O（PHP 内置服务器 100-Continue 延迟约 1s） |
| `HTTP GET /big?size=1048576 (client)` | 2.920ms | 200，1MB 响应 |
| `GET /curlproxy/verify (server)` | 1064.023ms | root span，覆盖全部 client span |

其他确认：

- `x-trace-id` 与 `traceparent` 均按当前请求注入；调用方原有的 `traceparent` 被替换。
- `url.full` 不含 userinfo；`http.request.headers` 中的 trace 头正常，敏感头在测试请求中未出现。
- Client Span 在响应后结束，`Span::getCurrent()` 在请求前后保持 root，无 Context 泄漏。
- 抓包中 `/big` 无 `http.response.body.size`，原因是审计服务未返回 `Content-Length`，符合预期。

### 8.5 OTel 自动加载

修复后在容器内执行 Provider/Span 冒烟测试，`Swoolefy\Library\OpenTelemetry\SDK\Trace\TracerProvider` 可正常实例化并结束 Span；worker 以 `OTEL_PHP_AUTOLOAD_ENABLED=true` 启动无 fatal，导出链路正常。

---

## 9. 回归清单

发布前建议按以下顺序检查：

1. **单测**：`vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/CurlProxy` 全绿。
2. **日志基础**：worker 内发起 GET/POST，确认 `guzzle_curl` 有 request/response 两条记录，path、query、traceId 正确。
3. **4xx/5xx**：请求 500 接口，确认响应体仍写入日志，同时业务抛出 `ServerException`；不要按“4xx/5xx 未记录”误报。
4. **大包体**：80MB 下载/上传在 `memory_limit=64M` 下不 OOM；1MB 响应日志出现截断标记且日志大小可控。
5. **流式场景**：`NoSeekStream` 请求体、非 seek `sink` 均成功；日志出现占位符，body 未被消费。
6. **同协程并发**：同一协程 `getAsync()` 发起两个不同 path 的请求，响应日志的 path/traceId 与各自请求一致。
7. **trace 注入**：Context 为空时保留调用方 `x-trace-id`；Context 后续写入能生效；traceId 含换行/超长时被清洗。
8. **OTel 生命周期**：开启 `OTEL_INSTRUMENTATION_GUZZLE_ENABLED`，确认 Client Span 耗时非 0、500 为 Error、parent 正确、无 Context 泄漏。
9. **OTel 脱敏**：构造带 `Authorization`/`Cookie` Header 与 `password`/`accessToken` 包体的请求，确认 Span 中为 `[REDACTED]`，原文不出现。
10. **OTel 自动加载**：`OTEL_PHP_AUTOLOAD_ENABLED=true` 启动 worker 不 fatal，能导出 Span。
11. **日志治理**：确认 512KB 上限、截断后缀、不可读流占位符；如日志含敏感数据，部署侧开启访问控制/采集脱敏。
12. **接口迁移**：确认调用方未再使用已删除的 `RequestMiddleware::addHeader()` / `ResponseMiddleware::limitLogResult()`。

---

## 10. 附录

### 10.1 环境与命令

```bash
# 单测
docker exec swoolefy-php85-v62 sh -lc \
  'cd /home/wwwroot/library && vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/CurlProxy'

# 审计 HTTP 服务（临时）
docker exec -d -w /home/wwwroot/library swoolefy-php85-v62 \
  php -S 127.0.0.1:18080 .curlproxy-audit/router.php

# Test 服务
docker exec -w /home/wwwroot/swoolefy swoolefy-php85-v62 php cli.php start Test --daemon=1
docker exec -w /home/wwwroot/swoolefy swoolefy-php85-v62 php cli.php stop Test --force=1

# E2E
docker exec swoolefy-php85-v62 curl -s http://127.0.0.1:9501/curlproxy/verify

# OTel 抓包（临时）
docker exec -d swoolefy-php85-v62 sh -lc \
  'php -S 127.0.0.1:4318 /home/wwwroot/library/.curlproxy-audit/otel_capture_server.php'
```

审计 HTTP 服务端点：`/echo`、`/status500`、`/big?size=`、`/slow`、`/sink`。

### 10.2 关键 Guzzle 语义

- `HandlerStack::resolve()` 逆序遍历栈，**先 push 的中间件在外层**。`http_errors` 由 `HandlerStack::create()` 先 push，因此位于最外层；响应日志在它内层执行，先于异常抛出。
- Guzzle 7.9 `CurlFactory::finish()` 会对可 seek 的响应体重卷；非 seek sink 不会。
- 默认 `sink` 是 `php://temp`（可 seek）；`NoSeekStream` 的 `isSeekable() === false`，`rewind()` 抛 `RuntimeException`。
- `PrepareBodyMiddleware` 对 1MB 以下包体可直接转字符串；大包体走 upload/readfunction，业务包体的实际消费发生在更内层的 Curl 执行阶段。

### 10.3 依赖版本

| 组件 | 版本/说明 |
|---|---|
| PHP | 8.5.8（容器） |
| Swoole | 6.2.2 |
| Guzzle | 7.9.x-dev |
| guzzlehttp/psr7 | 2.13.x-dev |
| PHPUnit | 11.5.57 |
| swoolefy | 同级仓库，提供 `Context` / `LogManager` / `Swfy` / `SystemEnv` |

### 10.4 临时验证资产

`.curlproxy-audit/` 下的审计 HTTP 服务、内存脚本、OTel 抓包/解码脚本，以及 swoolefy `Test` 服务中的 `CurlProxyVerifyController` + 路由均为本次验证的临时资产，审查结束后已清理；仓库内保留的正式资产为 `tests/CurlProxy/**`、`src/CurlProxy/README.md` 与 OTel 命名空间修复。

### 10.5 未纳入本次变更的观察

- 请求/响应日志保留原始包体（≤512KB），未做字段脱敏；如需合规，可在日志通道或采集端增加脱敏规则。
- `http.response.body.size` 依赖 `Content-Length`；chunked/流式响应不写该属性，避免为了观测而读取业务包体。
- 子协程不会自动继承 `traceparent`；如需把并发子协程的 Client Span 挂到父链路，调用方需自行传播 `traceparent`/`x-trace-id`。本次不改变框架的 Context 继承策略。
