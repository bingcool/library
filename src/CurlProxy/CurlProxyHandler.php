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

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Swfy;

class CurlProxyHandler
{
    const _HTTP_CURL_MAX_SPAN_DEFAULT = 100;

    const __HTTP_CURL_MAX_SPAN_TRACE = '__http_curl_max_span_trace';

    /** @var CurlFactoryInterface */
    private $factory;

    /**
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->factory = isset($options['handle_factory'])
            ? $options['handle_factory']
            : new CurlFactory(3);
    }

    /**
     * 魔术方法调用.
     *
     * @param RequestInterface $request
     * @param array            $options
     * @return \GuzzleHttp\Promise\FulfilledPromise|\GuzzleHttp\Promise\PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        if (isset($options['delay']) && is_numeric($options['delay'])) {
            usleep($options['delay'] * 1000);
        }

        /**
         * @var \GuzzleHttp\Handler\EasyHandle $easy
         */
        $easy = $this->factory->create($request, $options);
        curl_exec($easy->handle);
        $easy->errno = curl_errno($easy->handle);

        return CurlFactory::finish($this, $easy, $this->factory);
    }

    /**
     * @return \Swoolefy\Util\Log
     */
    public static function buildLogChannel()
    {
        if (\Swoole\Coroutine::getCid() >= 0) {
            $logger = LogManager::getInstance()->getLogger(LogManager::GUZZLE_CURL_LOG);
            if ($logger) {
                $logFilePath = $logger->getLogFilePath();
                if (!SwooleContext::has('is_exist_guzzle_curl_log_file')) {
                    if (!file_exists($logFilePath)) {
                        fopen($logFilePath, 'w');
                        SwooleContext::set('is_exist_guzzle_curl_log_file', 1);
                    }
                }
                return $logger;
            }
        }
    }

    /**
     * @return HandlerStack
     */
    public static function getStackHandler()
    {
        $handler = new static();
        $stack   = HandlerStack::create($handler);

        if (SwooleContext::has(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID)) {
            $traceId = SwooleContext::get(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID);
        }

        // 设置traceId
        $stack->push(RequestMiddleware::addHeader(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, $traceId ?? ''));
        // 记录请求参数
        $stack->push(RequestMiddleware::requestRecordLog());
        // 记录请求返回的原始数据
        $stack->push(ResponseMiddleware::responseRecordLog());
        // worker进程中开启opentelemetry追踪
        if (env('OTEL_INSTRUMENTATION_GUZZLE_ENABLED', false) && Swfy::isWorkerProcess()) {
            if (!SwooleContext::has(self::__HTTP_CURL_MAX_SPAN_TRACE)) {
                $currentTraceSpanNum = 1;
                SwooleContext::set(self::__HTTP_CURL_MAX_SPAN_TRACE, $currentTraceSpanNum);
            } else {
                $currentTraceSpanNum = SwooleContext::get(self::__HTTP_CURL_MAX_SPAN_TRACE);
                $maxTraceSpanNum = env('OTEL_INSTRUMENTATION_GUZZLE_MAX_TRACE_SPANS_NUM');
                if (empty($maxTraceSpanNum)) {
                    $maxTraceSpanNum = self::_HTTP_CURL_MAX_SPAN_DEFAULT;
                }
                if ($currentTraceSpanNum > $maxTraceSpanNum) {
                    return $stack;
                }
                // 自增1
                SwooleContext::set(self::__HTTP_CURL_MAX_SPAN_TRACE, ++$currentTraceSpanNum);
            }
            // 开始curl opentelemetry追踪
            $stack->push(OpentelemetryMiddleware::opentelemetryStartTrace());
            // 结束curl opentelemetry追踪
            $stack->push(OpentelemetryMiddleware::opentelemetryEndTrace());
        }

        return $stack;
    }
}
