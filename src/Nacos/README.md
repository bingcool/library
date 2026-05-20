# Nacos PHP SDK

Nacos client for PHP 8.2+, based on [Yurunsoft/nacos-php](https://github.com/Yurunsoft/nacos-php) API design to make it match swoolefy framework.

## Features

- Dynamic configuration (get / set / delete / long-polling listener)
- Service discovery (register / deregister / heartbeat / list / detail)
- Namespace & service management
- Operator APIs (metrics, switches, health)
- **Guzzle HTTP** transport (use common `guzzlehttp/guzzle` instead of `yurunsoft/yurun-http`)
- **Swoole coroutine** friendly: optional `Swoolefy\Core\Coroutine\CoroutinePools` Guzzle client pool
- Strongly typed response models

## Requirements

- PHP >= 8.2
- `guzzlehttp/guzzle` ~7.9
- Nacos Server >= 1.x (tested against `127.0.0.1:8848`)

## Quick start

```php
use Common\Library\Nacos\Client;
use Common\Library\Nacos\ClientConfig;

$config = new ClientConfig([
    'host' => '127.0.0.1',
    'port' => 8848,
    'username' => 'nacos',
    'password' => 'nacos',
    'authorizationBearer' => true,
]);

$client = new Client($config);

// Config
$client->config->set('app.yaml', 'DEFAULT_GROUP', 'key: value');
$value = $client->config->get('app.yaml', 'DEFAULT_GROUP');

// Service instance
$client->instance->register('192.168.1.10', 8080, 'my-service');
$hosts = $client->instance->list('my-service');
```

## Configuration

| Option | Default | Description |
|--------|---------|-------------|
| `host` | `127.0.0.1` | Nacos host |
| `port` | `8848` | Nacos port |
| `username` / `password` | empty | Auth credentials |
| `timeout` | `60000` | HTTP timeout (ms) |
| `authorizationBearer` | `false` | Send `Authorization: Bearer` header |
| `useCoroutinePool` | `true` | Pool Guzzle clients in Swoole coroutines via swoolefy |
| `maxConnections` | `16` | Coroutine pool size |
| `configParser` | `[]` | Custom config type parsers |

## Config listener (long polling)

```php
use Common\Library\Nacos\Provider\Config\Model\ListenerConfig;

$listener = $client->config->getConfigListener(new ListenerConfig([
    'timeout' => 30000,
    'failedWaitTime' => 3000,
]));

$listener->addListener('dataId', 'DEFAULT_GROUP', '', function ($listener, $dataId, $group, $tenant) {
    // config changed
});

// blocking loop (run in dedicated coroutine / process)
$listener->start();
```

## Tests

Requires a running Nacos at `127.0.0.1:8848` (default credentials `nacos` / `nacos`).

```bash
cd library
composer install
./vendor/bin/phpunit -c src/Nacos/phpunit.xml --testdox
```

Environment variables:

- `NACOS_TEST_HOST` (default `127.0.0.1`)
- `NACOS_TEST_PORT` (default `8848`)
- `NACOS_TEST_USERNAME` / `NACOS_TEST_PASSWORD`

## Swoole

Enable curl hook in worker start for non-blocking Guzzle:

```php
\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_CURL | SWOOLE_HOOK_NATIVE_CURL);
```

When `useCoroutinePool` is true and code runs inside a Swoole coroutine, Guzzle clients are borrowed from `Swoolefy\Core\Coroutine\CoroutinePools`.
