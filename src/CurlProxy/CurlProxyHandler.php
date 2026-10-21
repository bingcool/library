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

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Swfy;

class CurlProxyHandler
{
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
     * @return \Swoolefy\Util\Log|null
     */
    public static function buildLogChannel(): ?\Swoolefy\Util\Log
    {
        if (\Swoole\Coroutine::getCid() >= 0) {
            return LogManager::getInstance()->getLogger(LogManager::GUZZLE_CURL_LOG);
        }

        return null;
    }

    /**
     * 替换默认 prepare_body，避免 Content-Length(int) 触发 guzzlehttp/psr7 2.11+ 弃用警告。
     */
    public static function applyPsr7CompatiblePrepareBody(HandlerStack $stack): HandlerStack
    {
        $stack->remove('prepare_body');
        $stack->push(static function (callable $handler): PrepareBodyMiddleware {
            return new PrepareBodyMiddleware($handler);
        }, 'prepare_body');

        return $stack;
    }

    /**
     * @return HandlerStack
     */
    public static function getStackHandler()
    {
        $handler = new static();
        $stack   = HandlerStack::create($handler);
        self::applyPsr7CompatiblePrepareBody($stack);

        // 每次请求时从上下文读取 traceId，避免 HandlerStack 跨协程复用导致串值
        $stack->push(RequestMiddleware::addTraceIdHeader());
        // 记录请求参数
        $stack->push(RequestMiddleware::requestRecordLog());
        // 记录请求返回的原始数据
        $stack->push(ResponseMiddleware::responseRecordLog());
        // worker进程中开启opentelemetry追踪
        if (self::isOpentelemetryEnabled()) {
            // 开始curl opentelemetry追踪
            $stack->push(OpentelemetryMiddleware::opentelemetryStartTrace());
            // 结束curl opentelemetry追踪
            $stack->push(OpentelemetryMiddleware::opentelemetryEndTrace());
        }

        return $stack;
    }

    /**
     * 非 worker 进程（CLI、自定义进程）或未开启配置时不创建 Span。
     */
    private static function isOpentelemetryEnabled(): bool
    {
        if (!function_exists('env') || !env('OTEL_INSTRUMENTATION_GUZZLE_ENABLED', false)) {
            return false;
        }

        try {
            return Swfy::isWorkerProcess();
        } catch (\Throwable) {
            return false;
        }
    }
}
