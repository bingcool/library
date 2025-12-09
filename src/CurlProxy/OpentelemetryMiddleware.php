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

final class OpentelemetryMiddleware
{

    const OPENTELEMETRY_X_TRACE_ID = 'x-trace-id';

    const OPENTELEMETRY_TRACEPARENT_ID = 'traceparent';

    const OPENTELEMETRY_TRACE_ROOT_FLAG = '__trace_root_flag';

    const GUZZLE_CURL_PATH = '__guzzle_curl_path';

    const IS_CALL_ENDOPENTELEMETRY = '__is_call_end_opentelemetry';


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
