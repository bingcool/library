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
declare(strict_types=1);

namespace Swoolefy\Library\CurlProxy;

use Closure;
use Throwable;
use Psr\Http\Message\RequestInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContext;

final class RequestMiddleware
{
    /**
     * 请求包体写入日志的上限：512KB。
     */
    public const MAX_REQUEST_LOG_BYTES = 512 * 1024;

    private const TRACE_ID_MAX_LENGTH = 128;

    private const LOG_VALUE_MAX_LENGTH = 2048;

    /** @var \WeakMap<RequestInterface, array{path: string, trace_id: string}>|null */
    private static ?\WeakMap $requestInfo = null;

    /**
     * 每次请求时从协程上下文读取 traceId 注入请求头。
     *
     * 不能在构建 HandlerStack 时读取并固定：HandlerStack 通常被多个协程复用，
     * 构建时的 traceId 会串到其他请求，且会用空值覆盖调用方自带的 x-trace-id。
     */
    public static function addTraceIdHeader()
    {
        return static function (callable $handler) {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $traceId = self::currentTraceId();
                if ($traceId !== '') {
                    $request = $request->withHeader(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, $traceId);
                }

                return $handler($request, $options);
            };
        };
    }

    /**
     * 记录请求日志。只有注册了日志通道时才读取包体，避免无谓的内存与 IO 开销。
     *
     * @return Closure
     */
    public static function requestRecordLog()
    {
        $fn = static function (RequestInterface $request) {
            $path = '';
            $traceId = self::currentTraceId();
            try {
                $uri = $request->getUri();
                $path = self::sanitizeLogValue($uri->getPath() ?: '/');
                if ($uri->getQuery() !== '') {
                    $path .= '?' . self::sanitizeLogValue(urldecode($uri->getQuery()));
                }
            } catch (Throwable) {
            }

            try {
                self::requestInfoMap()[$request] = [
                    'path' => $path,
                    'trace_id' => $traceId,
                ];
            } catch (Throwable) {
            }

            try {
                $logger = CurlProxyHandler::buildLogChannel();
                if ($logger) {
                    $body = MessageBodyPreview::read($request->getBody(), self::MAX_REQUEST_LOG_BYTES);
                    $jsonData = [
                        'host' => $request->getUri()->getHost(),
                        'path' => $path,
                        'method' => $request->getMethod(),
                        'trace_id' => $traceId,
                        'body' => $body ?? '[body skipped: not readable or not seekable]',
                        'time' => date('Y-m-d H:i:s'),
                    ];

                    $dateTime = date('Y-m-d H:i:s');
                    $json = json_encode(
                        $jsonData,
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    );
                    $logger->info("【request@{$dateTime}】  api={$path}, traceId={$traceId}, 请求参数：" . ($json ?: '{}'));
                }
            } catch (Throwable) {
            }

            return $request;
        };

        return self::mapRequest($fn);
    }

    /**
     * 日志上下文按请求对象保存，避免同一协程内并发请求互相覆盖 path/trace_id。
     *
     * @return array{path: string, trace_id: string}
     */
    public static function getRequestInfo(RequestInterface $request): array
    {
        try {
            $info = self::requestInfoMap()[$request] ?? null;
        } catch (Throwable) {
            $info = null;
        }

        if (!is_array($info)) {
            return ['path' => '', 'trace_id' => ''];
        }

        return [
            'path' => (string) ($info['path'] ?? ''),
            'trace_id' => (string) ($info['trace_id'] ?? ''),
        ];
    }

    /**
     * @return \WeakMap<RequestInterface, array{path: string, trace_id: string}>
     */
    private static function requestInfoMap(): \WeakMap
    {
        return self::$requestInfo ??= new \WeakMap();
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
     * 读取并清洗当前协程的 traceId；无上下文、非标量或全为控制字符时返回空串。
     */
    private static function currentTraceId(): string
    {
        try {
            $traceId = SwooleContext::get(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID);
        } catch (Throwable) {
            return '';
        }

        if (!is_scalar($traceId)) {
            return '';
        }

        $traceId = preg_replace('/[\x00-\x20\x7F]+/', '', (string) $traceId) ?? '';
        if (strlen($traceId) > self::TRACE_ID_MAX_LENGTH) {
            $traceId = substr($traceId, 0, self::TRACE_ID_MAX_LENGTH);
        }

        return $traceId;
    }

    /**
     * 去除换行等控制字符，防止日志注入，并限制单字段长度。
     */
    private static function sanitizeLogValue(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        if (strlen($value) > self::LOG_VALUE_MAX_LENGTH) {
            $value = substr($value, 0, self::LOG_VALUE_MAX_LENGTH) . '...';
        }

        return $value;
    }
}
