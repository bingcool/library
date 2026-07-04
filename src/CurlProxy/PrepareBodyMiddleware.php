<?php

declare(strict_types=1);

namespace Swoolefy\Library\CurlProxy;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\MimeType;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle PrepareBodyMiddleware 兼容层。
 *
 * guzzlehttp/psr7 >= 2.11 要求 set_headers 值为 string；上游 PrepareBodyMiddleware
 * 仍传入 int 的 Content-Length，会触发 E_USER_DEPRECATED（Swoolefy 会转为异常）。
 * 此处将 Content-Length 转为 string。
 *
 * @see \GuzzleHttp\PrepareBodyMiddleware
 */
final class PrepareBodyMiddleware
{
    /** @var callable(RequestInterface, array): PromiseInterface */
    private $nextHandler;

    public function __construct(callable $nextHandler)
    {
        $this->nextHandler = $nextHandler;
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $fn = $this->nextHandler;

        if ($request->getBody()->getSize() === 0) {
            return $fn($request, $options);
        }

        $modify = [];

        if (!$request->hasHeader('Content-Type')) {
            $uri = $request->getBody()->getMetadata('uri');
            if (is_string($uri) && ($type = MimeType::fromFilename($uri))) {
                $modify['set_headers']['Content-Type'] = $type;
            }
        }

        if (!$request->hasHeader('Content-Length')
            && !$request->hasHeader('Transfer-Encoding')
        ) {
            $size = $request->getBody()->getSize();
            if ($size !== null) {
                // psr7 2.11+ requires string header values
                $modify['set_headers']['Content-Length'] = (string) $size;
            } else {
                $modify['set_headers']['Transfer-Encoding'] = 'chunked';
            }
        }

        $this->addExpectHeader($request, $options, $modify);

        return $fn(Psr7\Utils::modifyRequest($request, $modify), $options);
    }

    /** @param array<string, mixed> $options */
    private function addExpectHeader(RequestInterface $request, array $options, array &$modify): void
    {
        if ($request->hasHeader('Expect')) {
            return;
        }

        $expect = $options['expect'] ?? null;

        if ($expect === false || $request->getProtocolVersion() === '1.0') {
            return;
        }

        if ($expect === true) {
            $modify['set_headers']['Expect'] = '100-Continue';

            return;
        }

        if ($expect === null) {
            $expect = 1048576;
        }

        $body = $request->getBody();
        $size = $body->getSize();

        if ($size === null || $size >= (int) $expect || !$body->isSeekable()) {
            $modify['set_headers']['Expect'] = '100-Continue';
        }
    }
}
