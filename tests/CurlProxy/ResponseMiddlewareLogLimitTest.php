<?php

namespace Swoolefy\Library\Tests\CurlProxy;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\CurlProxy\ResponseMiddleware;

class ResponseMiddlewareLogLimitTest extends TestCase
{
    public function testResultWithinLimitIsKept(): void
    {
        $result = str_repeat('a', 128);

        $this->assertSame($result, ResponseMiddleware::limitLogResult($result));
    }

    public function testResultAtExactLimitIsKept(): void
    {
        $result = str_repeat('a', ResponseMiddleware::MAX_RESPONSE_LOG_BYTES);

        $this->assertSame($result, ResponseMiddleware::limitLogResult($result));
        $this->assertSame(ResponseMiddleware::MAX_RESPONSE_LOG_BYTES, strlen($result));
    }

    public function testResultOverLimitIsCappedAt1024Kb(): void
    {
        $result = str_repeat('b', ResponseMiddleware::MAX_RESPONSE_LOG_BYTES + 100);
        $logged = ResponseMiddleware::limitLogResult($result);

        $this->assertLessThanOrEqual(ResponseMiddleware::MAX_RESPONSE_LOG_BYTES, strlen($logged));
        $this->assertStringEndsWith(
            sprintf('...(truncated, %d bytes)', strlen($result)),
            $logged
        );
        $this->assertStringStartsWith('b', $logged);
    }
}
