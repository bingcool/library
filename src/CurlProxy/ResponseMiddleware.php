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
use Psr\Http\Message\ResponseInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContext;

final class ResponseMiddleware
{
    /**
     * 响应 result 写入日志的上限：512KB。
     * 下载、导出等大包体不能整段落盘，避免单条日志把磁盘和采集链路打满。
     */
    public const MAX_RESPONSE_LOG_BYTES = 512 * 1024;

    /**
     * 记录响应的数据.
     *
     * @return Closure
     */
    public static function responseRecordLog()
    {
        $fn = function (ResponseInterface $response) {
            $result = $response->getBody()->getContents();
            try {
                $logger = CurlProxyHandler::buildLogChannel();
                if ($logger) {
                    $info = SwooleContext::get(OpentelemetryMiddleware::GUZZLE_CURL_PATH);
                    $path = $info['path'] ?? '';
                    $traceId = $info['trace_id'] ?? '';
                    $dateTime = date('Y-m-d H:i:s');
                    $loggedResult = self::limitLogResult($result);
                    $logger->info("【response@{$dateTime}】 api={$path}, traceId={$traceId}, 响应数据：" . $loggedResult . "\r\n\r\n");
                }
            } catch (Throwable $exception) {
            }
            $response->getBody()->rewind();

            return $response;
        };

        return self::mapResponse($fn);
    }

    /**
     * 日志里的 result 最多保留 512KB。超出时截断，并附上原始字节数，避免把失败当成完整响应。
     * 截断按字节，再用 mb_strcut 收口，避免切在 UTF-8 字符中间。
     */
    public static function limitLogResult(string $result): string
    {
        $size = strlen($result);
        if ($size <= self::MAX_RESPONSE_LOG_BYTES) {
            return $result;
        }

        $suffix = sprintf('...(truncated, %d bytes)', $size);
        $keep = self::MAX_RESPONSE_LOG_BYTES - strlen($suffix);
        if ($keep < 0) {
            $keep = 0;
        }

        $head = substr($result, 0, $keep);
        if (function_exists('mb_strcut')) {
            $head = mb_strcut($head, 0, $keep, 'UTF-8');
        }

        return $head . $suffix;
    }


    /**
     * @param Closure $fn
     * @return Closure
     */
    public static function mapResponse(Closure $fn)
    {
        return function (callable $handler) use ($fn) {
            return function ($request, array $options) use ($handler, $fn) {
                /*
                 * @var RequestInterface $request
                 */
                return $handler($request, $options)->then($fn);
            };
        };
    }
}
