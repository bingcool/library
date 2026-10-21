<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\CurlProxy;

use Closure;
use Throwable;
use GuzzleHttp\Promise\Create;
use Swoolefy\Library\OpenTelemetry\API\Globals;
use Swoolefy\Library\OpenTelemetry\Context\Context as OpenTelemetryContext;
use Swoolefy\Library\OpenTelemetry\GuzzleAutoInstrumentation\HeadersPropagator;
use Swoolefy\Library\OpenTelemetry\SemConv\TraceAttributes;
use Swoolefy\Library\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use Swoolefy\Library\OpenTelemetry\API\Trace\SpanKind;
use Swoolefy\Library\OpenTelemetry\API\Trace\SpanInterface;
use Swoolefy\Library\OpenTelemetry\API\Trace\StatusCode;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContext;

final class OpentelemetryMiddleware
{
    const OPENTELEMETRY_X_TRACE_ID = 'x-trace-id';

    const OPENTELEMETRY_TRACEPARENT_ID = 'traceparent';

    const OPENTELEMETRY_TRACE_ROOT_FLAG = '__trace_root_flag';

    const GUZZLE_CURL_PATH = '__guzzle_curl_path';

    const IS_CALL_ENDOPENTELEMETRY = '__is_call_end_opentelemetry';

    /** 单个协程内允许创建的 Guzzle Client Span 上限 */
    public const DEFAULT_MAX_SPANS_PER_REQUEST = 100;

    /** 写入 http.request.body 属性的包体上限：32KB */
    private const MAX_REQUEST_BODY_ATTRIBUTE_BYTES = 32 * 1024;

    private const SPAN_COUNT_KEY = '__http_curl_span_count';

    private const SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-auth-token',
        'x-csrf-token',
    ];

    private const SENSITIVE_BODY_KEYS = [
        'password',
        'passwd',
        'pwd',
        'token',
        'accesstoken',
        'refreshtoken',
        'idtoken',
        'secret',
        'clientsecret',
        'secretkey',
        'privatekey',
        'authorization',
        'cookie',
        'credential',
        'credentials',
        'apikey',
        'xapikey',
        'session',
        'sessionid',
    ];

    /** @var \WeakMap<RequestInterface, SpanInterface>|null */
    private static ?\WeakMap $spans = null;

    /**
     * 发起请求前创建 Client Span，并把 traceparent 注入请求头。
     *
     * @return Closure
     */
    public static function opentelemetryStartTrace()
    {
        return static function (callable $handler) {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $tracedRequest = $request;
                $span = null;

                try {
                    if (self::consumeSpanBudget()) {
                        [$tracedRequest, $span] = self::startSpan($request);
                        if ($span !== null) {
                            self::spans()[$tracedRequest] = $span;
                        }
                    }
                } catch (Throwable) {
                    $tracedRequest = $request;
                    $span = null;
                }

                return $handler($tracedRequest, $options);
            };
        };
    }

    /**
     * 收到响应或异常后结束 Span。Span 必须在 HTTP 完成后结束，否则耗时恒为 0。
     *
     * @return Closure
     */
    public static function opentelemetryEndTrace()
    {
        return static function (callable $handler) {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $onFulfilled = static function (ResponseInterface $response) use ($request) {
                    try {
                        self::finishSpan($request, $response, null);
                    } catch (Throwable) {
                    }

                    return $response;
                };

                $onRejected = static function ($reason) use ($request) {
                    try {
                        self::finishSpan($request, null, $reason);
                    } catch (Throwable) {
                    }

                    return Create::rejectionFor($reason);
                };

                try {
                    return $handler($request, $options)->then($onFulfilled, $onRejected);
                } catch (Throwable $exception) {
                    try {
                        self::finishSpan($request, null, $exception);
                    } catch (Throwable) {
                    }
                    throw $exception;
                }
            };
        };
    }

    /**
     * @return array{0: RequestInterface, 1: SpanInterface|null}
     */
    private static function startSpan(RequestInterface $request): array
    {
        $propagator = TraceContextPropagator::getInstance();
        $parentContext = OpenTelemetryContext::getCurrent();
        $traceparent = self::currentTraceparent();
        if ($traceparent !== '') {
            $parentContext = $propagator->extract([self::OPENTELEMETRY_TRACEPARENT_ID => $traceparent]);
        }

        $uri = $request->getUri();
        $tracerName = function_exists('env') ? env('OTEL_TRACING_NAME', 'swoolefy-http-request') : 'swoolefy-http-request';

        $span = Globals::tracerProvider()
            ->getTracer($tracerName, '1.0.0')
            ->spanBuilder(sprintf(
                '%s %s %s (client)',
                strtoupper($uri->getScheme() ?: 'http'),
                strtoupper($request->getMethod()),
                $uri->getPath() ?: '/'
            ))
            ->setParent($parentContext)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        try {
            if ($span->isRecording()) {
                $span->setAttributes(self::buildAttributes($request));
                self::applyConfiguredHeaderAttributes($span, $request);
            }

            foreach ($propagator->fields() as $field) {
                $request = $request->withoutHeader($field);
            }

            $context = $span->storeInContext($parentContext);
            $propagator->inject($request, HeadersPropagator::instance(), $context);

            if ($span->isRecording()) {
                $span->setAttribute(
                    TraceAttributes::HTTP_REQUEST_HEADERS,
                    json_encode(
                        self::sanitizeHeaders($request->getHeaders()),
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    ) ?: '{}'
                );
            }
        } catch (Throwable $exception) {
            $span->end();
            throw $exception;
        }

        return [$request, $span];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildAttributes(RequestInterface $request): array
    {
        $uri = $request->getUri();

        $attributes = [
            TraceAttributes::URL_FULL => (string) $uri->withUserInfo(''),
            TraceAttributes::HTTP_REQUEST_METHOD => $request->getMethod(),
            TraceAttributes::NETWORK_PROTOCOL_VERSION => $request->getProtocolVersion(),
            TraceAttributes::SERVER_ADDRESS => $uri->getHost(),
            TraceAttributes::URL_PATH => $uri->getPath(),
            TraceAttributes::USER_AGENT_ORIGINAL => $request->getHeaderLine('User-Agent'),
        ];

        if ($uri->getQuery() !== '') {
            $attributes[TraceAttributes::HTTP_REQUEST_QUERY_PARAMS] = $uri->getQuery();
        }
        if ($uri->getPort() !== null) {
            $attributes[TraceAttributes::SERVER_PORT] = $uri->getPort();
        }

        $bodySize = $request->getBody()->getSize();
        if ($bodySize === null) {
            $contentLength = $request->getHeaderLine('Content-Length');
            $bodySize = ctype_digit($contentLength) ? (int) $contentLength : null;
        }
        if ($bodySize !== null) {
            $attributes[TraceAttributes::HTTP_REQUEST_BODY_SIZE] = $bodySize;
        }

        if (class_exists(\Swoole\Coroutine::class)) {
            $cid = \Swoole\Coroutine::getCid();
            if ($cid >= 0) {
                $attributes[TraceAttributes::COROUTINE_ID] = $cid;
            }
        }

        $body = self::previewRequestBody($request);
        if ($body !== null) {
            $attributes[TraceAttributes::HTTP_REQUEST_BODY] = $body;
        }

        return $attributes;
    }

    /**
     * 读取包体预览并脱敏；无法安全读取或非 JSON/表单时不记录包体。
     */
    private static function previewRequestBody(RequestInterface $request): ?string
    {
        $body = MessageBodyPreview::read($request->getBody(), self::MAX_REQUEST_BODY_ATTRIBUTE_BYTES);
        if ($body === null || $body === '') {
            return null;
        }

        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        if (str_contains($contentType, 'application/json')) {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return null;
            }
            if (!is_array($decoded)) {
                return null;
            }

            return json_encode(
                self::redactSensitiveData($decoded),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            ) ?: null;
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $parsed);

            return json_encode(
                self::redactSensitiveData($parsed),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            ) ?: null;
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function redactSensitiveData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveBodyKey($key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redactSensitiveData($value);
            }
        }

        return $data;
    }

    private static function isSensitiveBodyKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $key) ?? '');

        return in_array($normalized, self::SENSITIVE_BODY_KEYS, true);
    }

    /**
     * @param array<string, array<array-key, string>> $headers
     * @return array<string, array<array-key, string>>
     */
    private static function sanitizeHeaders(array $headers): array
    {
        foreach ($headers as $name => $values) {
            if (in_array(strtolower($name), self::SENSITIVE_HEADERS, true)) {
                $headers[$name] = ['[REDACTED]'];
            }
        }

        return $headers;
    }

    /**
     * 按 otel.instrumentation.http.request_headers 配置记录指定请求头（逗号分隔）。
     */
    private static function applyConfiguredHeaderAttributes(SpanInterface $span, RequestInterface $request): void
    {
        $configured = get_cfg_var('otel.instrumentation.http.request_headers');
        if (!is_string($configured) || trim($configured) === '') {
            return;
        }

        foreach (explode(',', $configured) as $header) {
            $header = trim($header);
            if ($header === '' || !$request->hasHeader($header)) {
                continue;
            }

            $span->setAttribute(
                sprintf('http.request.header.%s', strtolower($header)),
                $request->getHeader($header)
            );
        }
    }

    private static function currentTraceparent(): string
    {
        try {
            $traceparent = SwooleContext::get(self::OPENTELEMETRY_TRACEPARENT_ID);
        } catch (Throwable) {
            return '';
        }

        return is_scalar($traceparent) ? trim((string) $traceparent) : '';
    }

    /**
     * 每个协程独立计数；超过上限后不再创建 Span，避免递归调用产生海量 Span。
     */
    private static function consumeSpanBudget(): bool
    {
        $max = self::DEFAULT_MAX_SPANS_PER_REQUEST;
        if (function_exists('env')) {
            $configured = env('OTEL_INSTRUMENTATION_GUZZLE_MAX_TRACE_SPANS_NUM', $max);
            if (is_numeric($configured)) {
                $max = (int) $configured;
            }
        }

        if ($max <= 0) {
            return false;
        }

        try {
            $count = (int) SwooleContext::get(self::SPAN_COUNT_KEY, 0) + 1;
            SwooleContext::set(self::SPAN_COUNT_KEY, $count);

            return $count <= $max;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param mixed $reason
     */
    private static function finishSpan(RequestInterface $request, ?ResponseInterface $response, $reason): void
    {
        $spans = self::spans();
        if (!isset($spans[$request])) {
            return;
        }

        /** @var SpanInterface $span */
        $span = $spans[$request];
        unset($spans[$request]);

        try {
            if ($response !== null) {
                $status = $response->getStatusCode();
                $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $status);

                $contentLength = $response->getHeaderLine('Content-Length');
                if (ctype_digit($contentLength)) {
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, (int) $contentLength);
                }

                if ($status >= 400) {
                    $span->setStatus(StatusCode::STATUS_ERROR, 'HTTP status ' . $status);
                }
            } elseif ($reason instanceof Throwable) {
                $span->recordException($reason);
                $span->setStatus(StatusCode::STATUS_ERROR, $reason->getMessage());
            } else {
                $span->setStatus(StatusCode::STATUS_ERROR, 'HTTP request failed');
            }
        } finally {
            $span->end();
        }
    }

    /**
     * @return \WeakMap<RequestInterface, SpanInterface>
     */
    private static function spans(): \WeakMap
    {
        return self::$spans ??= new \WeakMap();
    }

    /**
     * @param Closure $fn
     * @return Closure
     */
    public static function mapRequest(Closure $fn)
    {
        return function (callable $handler) use ($fn) {
            return function ($request, array $options) use ($handler, $fn) {
                return $handler($fn($request), $options);
            };
        };
    }

    /**
     * @param Closure $fn
     * @param Closure|null $onRejected
     * @return Closure
     */
    public static function mapResponse(Closure $fn, ?Closure $onRejected = null)
    {
        return function (callable $handler) use ($fn, $onRejected) {
            return function ($request, array $options) use ($handler, $fn, $onRejected) {
                /*
                 * @var RequestInterface $request
                 */
                $promise = $handler($request, $options);

                return $onRejected === null ? $promise->then($fn) : $promise->then($fn, $onRejected);
            };
        };
    }
}
