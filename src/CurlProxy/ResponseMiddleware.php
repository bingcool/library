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
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ResponseMiddleware
{
    /**
     * 响应 result 写入日志的上限：512KB。
     * 下载、导出等大包体不能整段落盘，避免单条日志把磁盘和采集链路打满。
     */
    public const MAX_RESPONSE_LOG_BYTES = 512 * 1024;

    public const BODY_SKIPPED_PLACEHOLDER = '[body skipped: not readable or not seekable]';

    private const LOG_VALUE_MAX_LENGTH = 2048;

    /**
     * 记录响应的数据。只有注册了日志通道时才读取包体。
     *
     * @return Closure
     */
    public static function responseRecordLog()
    {
        $onFulfilled = static function (ResponseInterface $response, RequestInterface $request) {
            try {
                $logger = CurlProxyHandler::buildLogChannel();
                if ($logger) {
                    [$path, $traceId] = self::currentRequestInfo($request);
                    $result = MessageBodyPreview::read($response->getBody(), self::MAX_RESPONSE_LOG_BYTES)
                        ?? self::BODY_SKIPPED_PLACEHOLDER;
                    $dateTime = date('Y-m-d H:i:s');
                    $logger->info("【response@{$dateTime}】 api={$path}, traceId={$traceId}, 响应数据：" . $result . "\r\n\r\n");
                }
            } catch (Throwable) {
            }

            return $response;
        };

        $onRejected = static function ($reason, RequestInterface $request) {
            try {
                $logger = CurlProxyHandler::buildLogChannel();
                if ($logger) {
                    [$path, $traceId] = self::currentRequestInfo($request);
                    $error = $reason instanceof Throwable
                        ? sprintf('%s: %s', $reason::class, $reason->getMessage())
                        : (is_scalar($reason) ? (string) $reason : get_debug_type($reason));
                    $error = self::sanitizeLogValue($error);
                    $dateTime = date('Y-m-d H:i:s');
                    $logger->warning("【response-error@{$dateTime}】 api={$path}, traceId={$traceId}, 异常：" . $error . "\r\n\r\n");
                }
            } catch (Throwable) {
            }

            return Create::rejectionFor($reason);
        };

        return self::mapResponse($onFulfilled, $onRejected);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function currentRequestInfo(RequestInterface $request): array
    {
        $info = RequestMiddleware::getRequestInfo($request);

        return [
            self::sanitizeLogValue($info['path']),
            self::sanitizeLogValue($info['trace_id']),
        ];
    }

    private static function sanitizeLogValue(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        if (strlen($value) > self::LOG_VALUE_MAX_LENGTH) {
            $value = substr($value, 0, self::LOG_VALUE_MAX_LENGTH) . '...';
        }

        return $value;
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

                return $onRejected === null
                    ? $promise->then(static fn ($response) => $fn($response, $request))
                    : $promise->then(
                        static fn ($response) => $fn($response, $request),
                        static fn ($reason) => $onRejected($reason, $request)
                    );
            };
        };
    }
}
