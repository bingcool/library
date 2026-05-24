<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Adapter around PSR-7 / Guzzle response with Yurun-compatible helpers.
 */
final class HttpResponse
{
    private string $bodyCache = '';

    private bool $bodyLoaded = false;

    public function __construct(
        private readonly ResponseInterface $response,
    ) {
    }

    public function getPsrResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function body(): string
    {
        if (!$this->bodyLoaded) {
            $this->bodyCache = (string) $this->response->getBody();
            $this->bodyLoaded = true;
        }

        return $this->bodyCache;
    }

    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    /**
     * @return mixed
     */
    public function json(bool $assoc = true): mixed
    {
        $decoded = json_decode($this->body(), $assoc);

        return $assoc ? ($decoded ?? []) : $decoded;
    }
}
