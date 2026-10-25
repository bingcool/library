<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\CurlProxy;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;
use Swoolefy\Library\CurlProxy\RequestMiddleware;
use Swoolefy\Library\CurlProxy\ResponseMiddleware;
use Swoolefy\Library\OpenTelemetry\API\Globals;
use Swoolefy\Library\OpenTelemetry\API\Instrumentation\Configurator;
use Swoolefy\Library\OpenTelemetry\API\Trace\Span;
use Swoolefy\Library\OpenTelemetry\API\Trace\StatusCode;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\TracerProvider;

final class OpentelemetryMiddlewareTest extends TestCase
{
    private InMemoryExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        Globals::reset();
        $this->exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        Globals::registerInitializer(
            static fn (Configurator $configurator) => $configurator->withTracerProvider($provider)
        );
    }

    protected function tearDown(): void
    {
        putenv('OTEL_INSTRUMENTATION_GUZZLE_MAX_TRACE_SPANS_NUM');

        parent::tearDown();
    }

    public function testSpanEndsAfterHttpCall(): void
    {
        $this->runInCoroutine(function (): void {
            $client = $this->makeClient(static function () {
                usleep(120_000);

                return new FulfilledPromise(new Response());
            });

            $client->get('http://example.com/slow');

            $spans = $this->exporter->getSpans();
            self::assertCount(1, $spans);
            $duration = $spans[0]->getEndEpochNanos() - $spans[0]->getStartEpochNanos();
            self::assertGreaterThanOrEqual(100_000_000, $duration);
        });
    }

    public function testErrorResponseSetsStatusAndStatusCode(): void
    {
        $this->runInCoroutine(function (): void {
            $client = $this->makeClient(static fn () => new FulfilledPromise(new Response(500)));

            try {
                $client->get('http://example.com/500');
                self::fail('500 response should throw');
            } catch (ServerException) {
            }

            $spans = $this->exporter->getSpans();
            self::assertCount(1, $spans);
            self::assertSame(500, $spans[0]->getAttributes()->toArray()['http.response.status_code'] ?? null);
            self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        });
    }

    public function testRejectedRequestRecordsExceptionAndEndsSpan(): void
    {
        $this->runInCoroutine(function (): void {
            $request = new Request('GET', 'http://example.com/fail');
            $exception = new ConnectException('connection refused', $request);
            $client = $this->makeClient(static fn () => new RejectedPromise($exception));

            try {
                $client->get('http://example.com/fail');
                self::fail('Rejected request should throw');
            } catch (ConnectException $caught) {
                self::assertSame($exception, $caught);
            }

            $spans = $this->exporter->getSpans();
            self::assertCount(1, $spans);
            self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        });
    }

    public function testFormBodyIsRecordedRedactedAndLeftIntact(): void
    {
        $this->runInCoroutine(function (): void {
            $capturedBody = null;
            $client = $this->makeClient(
                static function (RequestInterface $request) use (&$capturedBody) {
                    $capturedBody = (string) $request->getBody();

                    return new FulfilledPromise(new Response());
                }
            );

            $client->post('http://example.com/form', [
                'form_params' => ['user' => 'abc', 'password' => 'secret'],
            ]);

            self::assertSame('user=abc&password=secret', $capturedBody);

            $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
            $recorded = json_decode($attributes['http.request.body'] ?? '', true);

            self::assertIsArray($recorded);
            self::assertSame('abc', $recorded['user']);
            self::assertSame('[REDACTED]', $recorded['password']);
            self::assertStringNotContainsString('secret', (string) ($attributes['http.request.body'] ?? ''));
        });
    }

    public function testJsonBodyIsRecordedRedactedAndLeftIntact(): void
    {
        $this->runInCoroutine(function (): void {
            $capturedBody = null;
            $client = $this->makeClient(
                static function (RequestInterface $request) use (&$capturedBody) {
                    $capturedBody = (string) $request->getBody();

                    return new FulfilledPromise(new Response());
                }
            );

            $client->post('http://example.com/json', [
                'json' => [
                    'access_token' => 'token-value',
                    'accessToken' => 'camel-token',
                    'nested' => ['client_secret' => 'nested-secret', 'ClientSecret' => 'camel-secret', 'ok' => 1],
                ],
            ]);

            self::assertJson($capturedBody);

            $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
            $recorded = json_decode($attributes['http.request.body'] ?? '', true);

            self::assertIsArray($recorded);
            self::assertSame('[REDACTED]', $recorded['access_token']);
            self::assertSame('[REDACTED]', $recorded['accessToken']);
            self::assertSame('[REDACTED]', $recorded['nested']['client_secret']);
            self::assertSame('[REDACTED]', $recorded['nested']['ClientSecret']);
            self::assertSame(1, $recorded['nested']['ok']);
        });
    }

    public function testTraceparentIsInjectedAndCallerTraceparentReplaced(): void
    {
        $this->runInCoroutine(function (): void {
            SwooleContext::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, 'framework-trace-id');

            $captured = null;
            $client = $this->makeClient(
                static function (RequestInterface $request) use (&$captured) {
                    $captured = $request;

                    return new FulfilledPromise(new Response());
                }
            );

            $client->get('http://example.com/trace', [
                'headers' => [
                    'x-trace-id' => 'caller-owned',
                    'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
                ],
            ]);

            self::assertSame('framework-trace-id', $captured->getHeaderLine('x-trace-id'));
            self::assertCount(1, $captured->getHeader('traceparent'));

            $span = $this->exporter->getSpans()[0];
            self::assertStringContainsString($span->getTraceId(), $captured->getHeaderLine('traceparent'));
            self::assertStringContainsString($span->getSpanId(), $captured->getHeaderLine('traceparent'));
        });
    }

    public function testClientSpanDoesNotLeakIntoCurrentContext(): void
    {
        $this->runInCoroutine(function (): void {
            $root = Globals::tracerProvider()->getTracer('test')->spanBuilder('root')->startSpan();
            $scope = $root->activate();

            try {
                $client = $this->makeClient(static fn () => new FulfilledPromise(new Response()));

                $client->get('http://example.com/one');
                self::assertSame(
                    $root->getContext()->getSpanId(),
                    Span::getCurrent()->getContext()->getSpanId()
                );

                $client->get('http://example.com/two');
                self::assertSame(
                    $root->getContext()->getSpanId(),
                    Span::getCurrent()->getContext()->getSpanId()
                );
            } finally {
                $scope->detach();
                $root->end();
            }

            $clientSpans = array_values(array_filter(
                $this->exporter->getSpans(),
                static fn ($span) => str_ends_with($span->getName(), '(client)')
            ));

            self::assertCount(2, $clientSpans);
            foreach ($clientSpans as $clientSpan) {
                self::assertSame($root->getContext()->getSpanId(), $clientSpan->getParentSpanId());
            }
        });
    }

    public function testSpanBudgetIsEnforcedPerRequest(): void
    {
        if (!class_exists(\Swoolefy\Core\SystemEnv::class)) {
            self::markTestSkipped('swoolefy SystemEnv is required');
        }

        \Swoolefy\Core\SystemEnv::enablePutenv();
        putenv('OTEL_INSTRUMENTATION_GUZZLE_MAX_TRACE_SPANS_NUM=2');

        try {
            $this->runInCoroutine(function (): void {
                $client = $this->makeClient(static fn () => new FulfilledPromise(new Response()));

                $client->get('http://example.com/1');
                $client->get('http://example.com/2');
                $client->get('http://example.com/3');

                self::assertCount(2, $this->exporter->getSpans());
            });
        } finally {
            putenv('OTEL_INSTRUMENTATION_GUZZLE_MAX_TRACE_SPANS_NUM');
            \Swoolefy\Core\SystemEnv::disablePutenv();
        }
    }

    private function makeClient(callable $terminal): Client
    {
        $stack = HandlerStack::create($terminal);
        $stack->push(RequestMiddleware::addTraceIdHeader());
        $stack->push(RequestMiddleware::requestRecordLog());
        $stack->push(ResponseMiddleware::responseRecordLog());
        $stack->push(OpentelemetryMiddleware::opentelemetryStartTrace());
        $stack->push(OpentelemetryMiddleware::opentelemetryEndTrace());

        return new Client(['handler' => $stack]);
    }

    private function runInCoroutine(callable $callback): void
    {
        if (!class_exists(\Swoole\Coroutine::class) || !class_exists(SwooleContext::class)) {
            self::markTestSkipped('Swoole coroutine and swoolefy context are required');
        }

        $error = null;
        \Swoole\Coroutine\run(static function () use ($callback, &$error): void {
            try {
                $callback();
            } catch (\Throwable $exception) {
                $error = $exception;
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }
}
