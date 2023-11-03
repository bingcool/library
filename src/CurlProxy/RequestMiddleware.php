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
use Psr\Http\Message\RequestInterface;
use Swoolefy\Core\Coroutine\Context;

final class RequestMiddleware
{
    /**
     * 设置请求头.
     *
     * @param $header
     * @param $value
     * @return Closure
     */
    public static function addHeader($header, $value)
    {
        return function (callable $handler) use ($header, $value) {
            return function (
                RequestInterface $request,
                array $options
            ) use ($handler, $header, $value) {
                $request = $request->withHeader($header, $value);

                return $handler($request, $options);
            };
        };
    }

    /**
     * @return Closure
     */
    public static function requestRecordLog()
    {
        $fn = function (RequestInterface $request) {
            try {
                $host = $request->getUri()->getHost();
                $path = $request->getUri()->getPath();
                if (!empty($request->getUri()->getQuery())) {
                    $path = $path . '?' . urldecode($request->getUri()->getQuery());
                }
                $method   = $request->getMethod();
                $body     = $request->getBody()->getContents();
                $traceId = '';
                if (Context::has('trace-id')) {
                    $traceId = Context::get('trace-id');
                }
                $jsonData = [
                    'host'   => $host,
                    'path'   => $path,
                    'method' => $method,
                    'trace_id' => $traceId,
                    'body'   => $body,
                ];

                $logger = CurlProxyHandler::buildLogChannel();
                if ($logger) {
                    Context::set('__guzzle_curl_path', [
                        'path'   => $path,
                        'trace_id' => $traceId ,
                    ]);

                    $logger->info("【请求】 api={$path}, traceId={$traceId}, 请求参数：" . json_encode($jsonData, JSON_UNESCAPED_UNICODE));
                }
            } catch (Throwable $exception) {
            }
            $request->getBody()->rewind();

            return $request;
        };

        return self::mapRequest($fn);
    }

    /**
     * @param $fn
     * @return Closure
     */
    public static function mapRequest($fn)
    {
        return function (callable $handler) use ($fn) {
            return function ($request, array $options) use ($handler, $fn) {
                return $handler($fn($request), $options);
            };
        };
    }
}
