<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Http;

use Swoolefy\Library\Nacos\ClientConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine;

/**
 * Guzzle HTTP transport with optional Swoole coroutine client pooling.
 */
final class GuzzleTransport
{
    public const POOL_NAME = 'library_nacos_guzzle';

    private ?GuzzleClient $client = null;

    private bool $poolRegistered = false;

    public function __construct(
        private readonly ClientConfig $config,
        private readonly ?string $poolKey = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options Guzzle request options
     *
     * @throws GuzzleException
     */
    public function request(string $method, string $uri, array $options = []): HttpResponse
    {
        if ($this->shouldUseCoroutinePool()) {
            return $this->requestViaPool($method, $uri, $options);
        }

        return new HttpResponse($this->getClient()->request($method, $uri, $options));
    }

    public function reopen(): void
    {
        $this->client = null;
        $this->poolRegistered = false;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws GuzzleException
     */
    private function requestViaPool(string $method, string $uri, array $options): HttpResponse
    {
        $pools = \Swoolefy\Core\Coroutine\CoroutinePools::getInstance();
        $poolName = $this->resolvePoolName();

        if (!$this->poolRegistered) {
            $config = $this->config;
            $pools->addPool($poolName, [
                'max_pool_num' => $config->getMaxConnections(),
                'max_push_timeout' => $config->getPoolWaitTimeout(),
                'max_pop_timeout' => $config->getPoolWaitTimeout(),
                'max_life_timeout' => 0,
            ], static fn (): GuzzleClient => self::createGuzzleClient($config));
            $this->poolRegistered = true;
        }

        /** @var GuzzleClient $client */
        $client = $pools->getObj($poolName);
        try {
            return new HttpResponse($client->request($method, $uri, $options));
        } finally {
            $pools->putObj($poolName, $client);
        }
    }

    private function shouldUseCoroutinePool(): bool
    {
        return $this->config->getUseCoroutinePool()
            && \extension_loaded('swoole')
            && Coroutine::getCid() > 0
            && class_exists(\Swoolefy\Core\Coroutine\CoroutinePools::class);
    }

    private function resolvePoolName(): string
    {
        $key = $this->poolKey ?? spl_object_hash($this->config);

        return self::POOL_NAME . '_' . $key;
    }

    private function getClient(): GuzzleClient
    {
        return $this->client ??= self::createGuzzleClient($this->config);
    }

    public static function createGuzzleClient(ClientConfig $config): GuzzleClient
    {
        $scheme = $config->getSsl() ? 'https' : 'http';
        $baseUri = sprintf(
            '%s://%s:%d%s',
            $scheme,
            $config->getHost(),
            $config->getPort(),
            rtrim($config->getPrefix(), '/') . '/',
        );

        return new GuzzleClient([
            'base_uri' => $baseUri,
            RequestOptions::TIMEOUT => $config->getTimeout() / 1000,
            RequestOptions::CONNECT_TIMEOUT => min(10, $config->getTimeout() / 1000),
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json, text/plain, */*',
            ],
        ]);
    }
}
