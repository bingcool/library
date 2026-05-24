<?php

declare(strict_types=1);

/**
 * Quick smoke test without PHPUnit (run: php src/Nacos/Test/smoke.php).
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Swoolefy\Library\Nacos\Client;
use Swoolefy\Library\Nacos\ClientConfig;

$host = getenv('NACOS_TEST_HOST') ?: '127.0.0.1';
$port = (int) (getenv('NACOS_TEST_PORT') ?: 8848);

$errno = 0;
$errstr = '';
if (false === @fsockopen($host, $port, $errno, $errstr, 2)) {
    fwrite(STDERR, "Skip: Nacos unavailable at {$host}:{$port}\n");
    exit(0);
}

$username = getenv('NACOS_TEST_USERNAME') ?: '';
$password = getenv('NACOS_TEST_PASSWORD') ?: '';

$client = new Client(new ClientConfig([
    'host' => $host,
    'port' => $port,
    'username' => $username,
    'password' => $password,
    'authorizationBearer' => '' !== $username && '' !== $password,
    'useCoroutinePool' => false,
]));

$dataId = 'library_nacos_smoke_' . time();
$group = 'DEFAULT_GROUP';

$client->config->set($dataId, $group, 'smoke-value');
usleep(100_000);
$value = $client->config->get($dataId, $group);
assert('smoke-value' === $value, 'config get mismatch');
$client->config->delete($dataId, $group);

$metrics = $client->operator->metrics();
assert($metrics->getStatus() === 'UP' || $metrics->getStatus() !== '', 'metrics status');

echo "Nacos smoke test OK\n";
