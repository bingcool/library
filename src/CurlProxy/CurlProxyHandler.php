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
use Swoolefy\Core\Coroutine\Context;

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
     * @return \Swoolefy\Util\Log
     */
    public static function buildLogChannel()
    {
        if (\Swoole\Coroutine::getCid() >= 0) {
            $logger = \Swoolefy\Core\Log\LogManager::getInstance()->getLogger('guzzle_curl_log');
            if ($logger) {
                $logFilePath = $logger->getLogFilePath();
                if (!Context::has('is_exist_guzzle_curl_log_file')) {
                    if (!file_exists($logFilePath)) {
                        fopen($logFilePath, 'w');
                        Context::set('is_exist_guzzle_curl_log_file', 1);
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

        if (Context::has('trace-id')) {
            $traceId = Context::get('trace-id');
        }

        // 设置traceId
        $stack->push(RequestMiddleware::addHeader('trace-id', $traceId ?? ''));
        // 记录请求参数
        $stack->push(RequestMiddleware::requestRecordLog());
        // 记录请求返回的原始数据
        $stack->push(ResponseMiddleware::responseRecordLog());
        return $stack;
    }
}
