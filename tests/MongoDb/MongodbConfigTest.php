<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\MongoDb;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Mongodb\Config\MongodbConfig;

final class MongodbConfigTest extends TestCase
{
    public function testValidConfigProvidesAccessors(): void
    {
        $config = new MongodbConfig([
            'uri' => 'mongodb://user:pass@127.0.0.1:27017/?authSource=admin',
            'database' => 'library',
            'id_key' => 'id',
            'object_id' => false,
        ]);

        $this->assertSame('mongodb://user:pass@127.0.0.1:27017/?authSource=admin', $config->uri());
        $this->assertSame('library', $config->database());
        $this->assertSame('id', $config->idKey());
        $this->assertFalse($config->objectId());
        $this->assertSame([], $config->driverOptions());
    }

    public function testDefaultTimeoutsAreApplied(): void
    {
        $config = new MongodbConfig([
            'uri' => 'mongodb://127.0.0.1:27017',
            'database' => 'library',
        ]);

        $this->assertSame(
            [
                'serverSelectionTimeoutMS' => 3000,
                'connectTimeoutMS' => 2000,
                'socketTimeoutMS' => 30000,
            ],
            $config->uriOptions()
        );
    }

    public function testUserUriOptionsOverrideDefaults(): void
    {
        $config = new MongodbConfig([
            'uri' => 'mongodb://127.0.0.1:27017',
            'database' => 'library',
            'uri_options' => [
                'socketTimeoutMS' => 1000,
                'retryWrites' => false,
            ],
        ]);

        $this->assertSame(1000, $config->uriOptions()['socketTimeoutMS']);
        $this->assertFalse($config->uriOptions()['retryWrites']);
        $this->assertSame(3000, $config->uriOptions()['serverSelectionTimeoutMS']);
        $this->assertSame(2000, $config->uriOptions()['connectTimeoutMS']);
    }

    public function testUnknownKeysAreRejectedWithoutLeakingValues(): void
    {
        try {
            new MongodbConfig([
                'uri' => 'mongodb://secret-user:secret-pass@127.0.0.1:27017',
                'database' => 'library',
                'timeout' => 10,
            ]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('timeout', $exception->getMessage());
            $this->assertStringNotContainsString('secret-user', $exception->getMessage());
            $this->assertStringNotContainsString('secret-pass', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'missing uri' => [['database' => 'library'], 'uri'];
        yield 'empty uri' => [['uri' => '', 'database' => 'library'], 'uri'];
        yield 'non-string uri' => [['uri' => 123, 'database' => 'library'], 'uri'];
        yield 'wrong scheme' => [['uri' => 'mongodb=127.0.0.1:27017', 'database' => 'library'], 'uri'];
        yield 'missing database' => [['uri' => 'mongodb://127.0.0.1:27017'], 'database'];
        yield 'empty database' => [['uri' => 'mongodb://127.0.0.1:27017', 'database' => ''], 'database'];
        yield 'long database' => [['uri' => 'mongodb://127.0.0.1:27017', 'database' => str_repeat('a', 65)], 'database'];
        yield 'slash in database' => [['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'a/b'], 'database'];
        yield 'dot in database' => [['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'a.b'], 'database'];
        yield 'space in database' => [['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'a b'], 'database'];
        yield 'dollar in database' => [['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'a$b'], 'database'];
        yield 'null byte in database' => [['uri' => 'mongodb://127.0.0.1:27017', 'database' => "a\0b"], 'database'];
        yield 'non-array uri options' => [
            ['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'library', 'uri_options' => 'x'],
            'uri_options',
        ];
        yield 'non-array driver options' => [
            ['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'library', 'driver_options' => 'x'],
            'driver_options',
        ];
        yield 'typeMap in driver options' => [
            [
                'uri' => 'mongodb://127.0.0.1:27017',
                'database' => 'library',
                'driver_options' => ['typeMap' => ['root' => 'array']],
            ],
            'typeMap',
        ];
        yield 'codec in driver options' => [
            [
                'uri' => 'mongodb://127.0.0.1:27017',
                'database' => 'library',
                'driver_options' => ['codec' => new \stdClass()],
            ],
            'codec',
        ];
        yield 'empty id_key' => [
            ['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'library', 'id_key' => ''],
            'id_key',
        ];
        yield 'dotted id_key' => [
            ['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'library', 'id_key' => 'meta.id'],
            'id_key',
        ];
        yield 'dollar id_key' => [
            ['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'library', 'id_key' => '$id'],
            'id_key',
        ];
        yield 'null byte id_key' => [
            ['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'library', 'id_key' => "i\0d"],
            'id_key',
        ];
        yield 'non-bool object_id' => [
            ['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'library', 'object_id' => 1],
            'object_id',
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigProvider')]
    public function testInvalidConfigIsRejected(array $config, string $expectedFragment): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedFragment, '/') . '/');

        new MongodbConfig($config);
    }

    public function testDebugInfoRedactsUri(): void
    {
        $config = new MongodbConfig([
            'uri' => 'mongodb://secret-user:secret-pass@127.0.0.1:27017/?authSource=admin',
            'database' => 'library',
            'id_key' => 'id',
        ]);

        $debug = $config->__debugInfo();

        $this->assertSame('[redacted]', $debug['uri']);
        $this->assertSame('library', $debug['database']);
        $this->assertSame('id', $debug['id_key']);
        $this->assertSame(['serverSelectionTimeoutMS', 'connectTimeoutMS', 'socketTimeoutMS'], $debug['uri_options']);
        $this->assertSame([], $debug['driver_options']);
        $this->assertStringNotContainsString('secret-pass', print_r($debug, true));
    }
}
