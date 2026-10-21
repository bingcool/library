<?php

namespace Swoolefy\Library\Tests\CurlProxy;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Swoolefy\Library\CurlProxy\ResponseMiddleware;

final class ResponseMiddlewareTest extends TestCase
{
    public function testNonSeekableResponseBodyIsLeftUntouched(): void
    {
        $body = new NoSeekStream(Utils::streamFor('response-body'));
        $response = new Response(200, [], $body);

        $wrapped = ResponseMiddleware::responseRecordLog()(
            static fn () => new FulfilledPromise($response)
        );

        $result = $wrapped(new Request('GET', 'http://example.com/'), [])->wait();

        self::assertSame($response, $result);
        self::assertSame('response-body', $body->getContents());
    }

    public function testRejectionReasonIsPropagated(): void
    {
        $request = new Request('GET', 'http://example.com/');
        $exception = new ConnectException('connection refused', $request);

        $wrapped = ResponseMiddleware::responseRecordLog()(
            static fn () => new RejectedPromise($exception)
        );

        try {
            $wrapped($request, [])->wait();
            self::fail('Rejection reason should not be swallowed');
        } catch (ConnectException $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function testMapResponseWithoutRejectionHandlerKeepsPromiseChain(): void
    {
        $response = new Response(204);
        $wrapped = ResponseMiddleware::mapResponse(static fn ($response) => $response)(
            static fn () => new FulfilledPromise($response)
        );

        self::assertSame($response, $wrapped(new Request('GET', 'http://example.com/'), [])->wait());
    }

    public function testMapResponsePassesRequestToCallbacks(): void
    {
        $request = new Request('GET', 'http://example.com/');
        $response = new Response(204);
        $seen = null;

        $wrapped = ResponseMiddleware::mapResponse(
            static function ($response, RequestInterface $callbackRequest) use (&$seen) {
                $seen = $callbackRequest;

                return $response;
            }
        )(static fn () => new FulfilledPromise($response));

        self::assertSame($response, $wrapped($request, [])->wait());
        self::assertSame($request, $seen);
    }
}
