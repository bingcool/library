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

namespace Common\Library\CurlProxy;

use Closure;
use Throwable;
use Psr\Http\Message\ResponseInterface;
use Swoolefy\Core\Coroutine\Context;

final class ResponseMiddleware
{
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
                    $info = Context::get('__guzzle_curl_path');
                    $path = $info['path'] ?? '';
                    $traceId = $info['trace_id'] ?? '';
                    $dateTime = date('Y-m-d H:i:s');
                    $logger->info("【response@{$dateTime}】 api={$path}, traceId={$traceId}, 响应数据：" . $result . "\r\n\r\n");
                }
            } catch (Throwable $exception) {
            }
            $response->getBody()->rewind();

            return $response;
        };

        return self::mapResponse($fn);
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
