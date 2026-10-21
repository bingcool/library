<?php

namespace Swoolefy\Library\Tests\CurlProxy;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;
use Swoolefy\Library\CurlProxy\RequestMiddleware;

final class RequestMiddlewareTest extends TestCase
{
    public function testTraceIdHeaderIsReadPerRequest(): void
    {
        $this->runInCoroutine(function (): void {
            $captured = [];
            $client = $this->makeClient(static function (RequestInterface $request) use (&$captured) {
                $captured[] = $request->getHeaderLine(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID);

                return new FulfilledPromise(new Response());
            });

            SwooleContext::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, 'trace-one');
            $client->get('http://example.com/one');

            SwooleContext::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, 'trace-two');
            $client->get('http://example.com/two');

            self::assertSame(['trace-one', 'trace-two'], $captured);
        });
    }

    public function testEmptyContextDoesNotOverwriteCallerHeader(): void
    {
        $captured = null;
        $client = $this->makeClient(static function (RequestInterface $request) use (&$captured) {
            $captured = $request->getHeaderLine(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID);

            return new FulfilledPromise(new Response());
        });

        $client->get('http://example.com/', [
            'headers' => [OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID => 'caller-owned'],
        ]);

        self::assertSame('caller-owned', $captured);
    }

    public function testControlCharactersAreStrippedFromTraceId(): void
    {
        $this->runInCoroutine(function (): void {
            $captured = null;
            $client = $this->makeClient(static function (RequestInterface $request) use (&$captured) {
                $captured = $request->getHeaderLine(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID);

                return new FulfilledPromise(new Response());
            });

            SwooleContext::set(
                OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID,
                " trace-id\r\nInjected-Header: 1 "
            );

            $client->get('http://example.com/');

            self::assertSame('trace-idInjected-Header:1', $captured);
        });
    }

    public function testRequestLogDoesNotConsumeNonSeekableBody(): void
    {
        $body = new NoSeekStream(Utils::streamFor('payload'));
        $request = new Request('POST', 'http://example.com/', ['Content-Type' => 'text/plain'], $body);
        $captured = null;

        $wrapped = RequestMiddleware::requestRecordLog()(
            static function (RequestInterface $request) use (&$captured) {
                $captured = $request->getBody()->getContents();

                return new FulfilledPromise(new Response());
            }
        );

        $wrapped($request, [])->wait();

        self::assertSame('payload', $captured);
    }

    public function testRequestInfoIsScopedPerRequestObject(): void
    {
        $this->runInCoroutine(function (): void {
            $wrapped = RequestMiddleware::requestRecordLog()(
                static fn () => new FulfilledPromise(new Response())
            );

            SwooleContext::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, 'trace-second');
            $second = new Request('GET', 'http://example.com/second?x=1');
            $wrapped($second, [])->wait();

            SwooleContext::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, 'trace-first');
            $first = new Request('GET', 'http://example.com/first');
            $wrapped($first, [])->wait();

            self::assertSame(
                ['path' => '/second?x=1', 'trace_id' => 'trace-second'],
                RequestMiddleware::getRequestInfo($second)
            );
            self::assertSame(
                ['path' => '/first', 'trace_id' => 'trace-first'],
                RequestMiddleware::getRequestInfo($first)
            );
            self::assertSame(
                ['path' => '', 'trace_id' => ''],
                RequestMiddleware::getRequestInfo(new Request('GET', 'http://example.com/unknown'))
            );
        });
    }

    private function makeClient(callable $terminal): Client
    {
        $stack = HandlerStack::create($terminal);
        $stack->push(RequestMiddleware::addTraceIdHeader());

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
