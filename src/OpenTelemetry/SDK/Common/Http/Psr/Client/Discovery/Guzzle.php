<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Client\ClientInterface;
use Swoolefy\Library\CurlProxy\CurlProxyHandler;

class Guzzle implements DiscoveryInterface
{
    #[\Override]
    public function available(): bool
    {
        return class_exists(Client::class) && is_a(Client::class, ClientInterface::class, true);
    }

    #[\Override]
    public function create(mixed $options): ClientInterface
    {
        if (!isset($options['handler'])) {
            $stack = HandlerStack::create();
            CurlProxyHandler::applyPsr7CompatiblePrepareBody($stack);
            $options['handler'] = $stack;
        }

        return new Client($options);
    }
}
