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
use Common\Library\OpenTelemetry\API\Globals;
use Common\Library\OpenTelemetry\Context\Context as OpenTelemetryContext;
use Common\Library\OpenTelemetry\GuzzleAutoInstrumentation\HeadersPropagator;
use Common\Library\OpenTelemetry\SemConv\TraceAttributes;
use Common\Library\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use Common\Library\OpenTelemetry\API\Trace\Span;
use Common\Library\OpenTelemetry\API\Trace\SpanKind;;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Common\Library\OpenTelemetry\Context\Context;

final class OpentelemetryMiddleware
{

    const OPENTELEMETRY_X_TRACE_ID = 'x-trace-id';

    const OPENTELEMETRY_TRACEPARENT_ID = 'traceparent';

    const OPENTELEMETRY_TRACE_ROOT_FLAG = '__trace_root_flag';

    const GUZZLE_CURL_PATH = '__guzzle_curl_path';

    /**
     * @return Closure
     */
    public static function opentelemetryStartTrace()
    {
        $fn = function (RequestInterface $request) {
            $propagator    = TraceContextPropagator::getInstance();
            $parentContext = OpenTelemetryContext::getCurrent();
            $traceparent   = SwooleContext::get(self::OPENTELEMETRY_TRACEPARENT_ID);
            if (!empty($traceparent)) {
                $carrier[self::OPENTELEMETRY_TRACEPARENT_ID] = $traceparent;
                $parentContext = TraceContextPropagator::getInstance()->extract($carrier);
            }

            $spanBuilder = Globals::tracerProvider()->getTracer(env('OTEL_TRACING_NAME','swoolefy-http-request'), '1.0.0')
                ->spanBuilder(sprintf('%s %s %s (client)', strtoupper($request->getUri()->getScheme() ?: 'HTTP'), $request->getMethod(), $request->getUri()->getPath()))
                ->setParent($parentContext)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->startSpan();

            $span = $spanBuilder
                ->setAttribute(TraceAttributes::COROUTINE_ID, \Swoole\Coroutine::getCid())
                ->setAttribute(TraceAttributes::HTTP_CLIENT_HOST, gethostname())
                ->setAttribute(TraceAttributes::CLIENT_ADDRESS, gethostname())
                ->setAttribute(TraceAttributes::URL_FULL, (string) $request->getUri())
                ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY, self::handleRequestBody($request))
                ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                ->setAttribute(TraceAttributes::HTTP_REQUEST_QUERY_PARAMS, $request->getUri()->getQuery())
                ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort())
                ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                ->setAttribute(TraceAttributes::HTTP_REQUEST_HEADERS, json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE))
            ;

            foreach ($propagator->fields() as $field) {
                $request = $request->withoutHeader($field);
            }
            foreach ((array) (get_cfg_var('otel.instrumentation.http.request_headers') ?: []) as $header) {
                if ($request->hasHeader($header)) {
                    $spanBuilder->setAttribute(
                        sprintf('http.request.header.%s', strtolower($header)),
                        $request->getHeader($header)
                    );
                }
            }

            if ($span instanceof Span) {
                $span->setAttribute(TraceAttributes::HTTP_REQUEST_HEADERS, json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE));
            }

            $scope   = $span->activate();
            $context = $span->storeInContext($parentContext);
            $propagator->inject($request, HeadersPropagator::instance(), $context);
            Context::storage()->attach($context);
            $span->end();
            $scope->detach();
            return $request;
        };

        return self::mapRequest($fn);
    }

    /**
     * @return mixed
     */
    public static function opentelemetryEndTrace()
    {
        $fn = function (ResponseInterface $response) {
            return $response;
        };
        return self::mapResponse($fn);
    }

    /**
     * @param RequestInterface $request
     * @return false|string
     */
    private static function handleRequestBody(RequestInterface $request)
    {
        $contentType = $request->getHeaderLine('Content-Type');
        $postData = [];

        if (str_contains($contentType, 'application/json')) {
            $postData = json_decode((string)$request->getBody(), true);
        } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $postData = $request->getParsedBody();
        }

        return json_encode($postData,JSON_UNESCAPED_UNICODE);
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
