<?php

namespace Swoolefy\Library\Tests\CurlProxy;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Swoolefy\Library\CurlProxy\MessageBodyPreview;

final class MessageBodyPreviewTest extends TestCase
{
    public function testReadsFromStartAndRewinds(): void
    {
        $body = Utils::streamFor('0123456789');
        $body->seek(4);

        self::assertSame('0123456789', MessageBodyPreview::read($body, 100));
        self::assertSame(0, $body->tell());
        self::assertSame('0123456789', $body->getContents());
    }

    public function testBodyAtExactLimitIsNotMarkedTruncated(): void
    {
        $body = Utils::streamFor(str_repeat('a', 50));

        $preview = MessageBodyPreview::read($body, 50);

        self::assertSame(str_repeat('a', 50), $preview);
        self::assertStringNotContainsString('truncated', (string) $preview);
    }

    public function testOverLimitUsesKnownStreamSize(): void
    {
        $body = Utils::streamFor(str_repeat('a', 100));

        $preview = MessageBodyPreview::read($body, 50);

        self::assertNotNull($preview);
        self::assertLessThanOrEqual(50, strlen($preview));
        self::assertStringStartsWith('a', $preview);
        self::assertStringEndsWith('...(truncated, 100 bytes)', $preview);
        self::assertSame(0, $body->tell());
    }

    public function testOverLimitWithoutKnownSize(): void
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        fwrite($resource, str_repeat('b', 100));
        rewind($resource);

        $body = new class($resource) extends Stream {
            public function getSize(): ?int
            {
                return null;
            }
        };

        $preview = MessageBodyPreview::read($body, 50);

        self::assertNotNull($preview);
        self::assertLessThanOrEqual(50, strlen($preview));
        self::assertStringEndsWith('...(truncated, >50 bytes)', $preview);
    }

    public function testNonSeekableBodyIsNotConsumed(): void
    {
        $body = new NoSeekStream(Utils::streamFor('payload'));

        self::assertNull(MessageBodyPreview::read($body, 1024));
        self::assertSame('payload', $body->getContents());
    }

    public function testNonReadableBodyReturnsNull(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'curlproxy-preview');
        self::assertNotFalse($path);
        $resource = fopen($path, 'w');
        self::assertIsResource($resource);

        try {
            $body = Utils::streamFor($resource);

            self::assertNull(MessageBodyPreview::read($body, 1024));
        } finally {
            fclose($resource);
            @unlink($path);
        }
    }

    public function testTruncationKeepsUtf8Valid(): void
    {
        $body = Utils::streamFor(str_repeat('中', 100));

        $preview = MessageBodyPreview::read($body, 40);

        self::assertNotNull($preview);
        self::assertLessThanOrEqual(40, strlen($preview));
        self::assertTrue(mb_check_encoding($preview, 'UTF-8'));
    }

    public function testZeroLimitReturnsNull(): void
    {
        self::assertNull(MessageBodyPreview::read(Utils::streamFor('abc'), 0));
    }

    public function testSmallLimitDoesNotSplitUtf8Character(): void
    {
        $truncated = MessageBodyPreview::truncate(str_repeat('中', 20), 10);

        self::assertLessThanOrEqual(10, strlen($truncated));
        self::assertTrue(mb_check_encoding($truncated, 'UTF-8'));
    }
}
