<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Test;

use PHPUnit\Framework\TestCase;
use Common\Library\Nacos\Client;
use Common\Library\Nacos\ClientConfig;

abstract class BaseTest extends TestCase
{
    protected ?Client $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNacosUnavailable();
    }

    protected function getClient(): Client
    {
        return $this->client ??= $this->getNewClient();
    }

    protected function getNewClient(): Client
    {
        $username = getenv('NACOS_TEST_USERNAME') ?: '';
        $password = getenv('NACOS_TEST_PASSWORD') ?: '';

        return new Client(new ClientConfig([
            'host'                => getenv('NACOS_TEST_HOST') ?: '127.0.0.1',
            'port'                => (int) (getenv('NACOS_TEST_PORT') ?: 8848),
            'username'            => $username,
            'password'            => $password,
            'authorizationBearer' => '' !== $username && '' !== $password,
            'configParser'        => [
                'test' => static fn (string $value): array => ['data' => $value],
            ],
        ]));
    }

    protected function skipNoSwoole(): void
    {
        if (!\defined('SWOOLE_VERSION')) {
            $this->markTestSkipped('no swoole');
        }
    }

    protected function skipIfNacosUnavailable(): void
    {
        $host = getenv('NACOS_TEST_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('NACOS_TEST_PORT') ?: 8848);
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        if (false === $socket) {
            $this->markTestSkipped(sprintf('Nacos server unavailable at %s:%d (%s)', $host, $port, $errstr ?: (string) $errno));
        }
        fclose($socket);
    }
}
