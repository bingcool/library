<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\MongoDb;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;
use Swoolefy\Library\Mongodb\MongodbModel;
use Throwable;

/**
 * 协程内使用 MongoDB 官方 Client 的兼容性测试。
 *
 * 官方 Client 调用仍然阻塞 Worker；本用例只验证协程内创建模型与 CRUD 不报错。
 */
final class MongodbCoroutineTest extends TestCase
{
    private static string $uri = '';

    private static string $database = '';

    private string $collection = '';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('mongodb')) {
            self::markTestSkipped('ext-mongodb is not loaded.');
        }

        if (version_compare((string) phpversion('mongodb'), '2.3.0', '<')) {
            self::markTestSkipped('ext-mongodb >= 2.3 is required.');
        }

        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ext-swoole is not loaded.');
        }

        $uri = getenv('MONGO_URI');
        if (!is_string($uri) || $uri === '') {
            self::markTestSkipped('MONGO_URI is not set.');
        }

        $database = getenv('MONGO_DB');
        self::$uri = $uri;
        self::$database = is_string($database) && $database !== '' ? $database : 'phpunit_library';

        try {
            $model = new MongodbModel([
                'uri' => self::$uri,
                'database' => self::$database,
                'uri_options' => ['serverSelectionTimeoutMS' => 2000],
            ]);

            if (!$model->ping()) {
                self::markTestSkipped('MongoDB ping failed.');
            }
        } catch (Throwable $exception) {
            self::markTestSkipped('MongoDB is not reachable: ' . $exception->getMessage());
        }
    }

    protected function setUp(): void
    {
        if (self::$uri === '') {
            self::markTestSkipped('MongoDB is not available.');
        }

        $this->collection = 'phpunit_mongodb_coroutine_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->collection === '' || self::$uri === '') {
            return;
        }

        try {
            $model = new MongodbModel(['uri' => self::$uri, 'database' => self::$database]);
            $model->collection($this->collection)->raw()->drop();
        } catch (Throwable) {
            // 清理失败不覆盖测试结果
        }
    }

    public function testCrudInsideCoroutines(): void
    {
        $results = [];
        $errors = [];

        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        Coroutine\run(function () use (&$results, &$errors): void {
            for ($index = 0; $index < 2; $index++) {
                go(function () use ($index, &$results, &$errors): void {
                    try {
                        $model = new MongodbModel([
                            'uri' => self::$uri,
                            'database' => self::$database,
                            'uri_options' => ['serverSelectionTimeoutMS' => 2000],
                        ]);

                        $collection = $model->collection($this->collection);
                        $id = sprintf('%024d', $index + 1);
                        $inserted = $collection->insertOne(['_id' => $id, 'index' => $index]);
                        $found = $collection->findOne(['_id' => $id]);

                        $results[$index] = [
                            'cid' => Coroutine::getCid(),
                            'id' => (string) $inserted->getInsertedId(),
                            'found' => $found,
                            'pong' => $model->ping(),
                        ];
                    } catch (Throwable $exception) {
                        $errors[$index] = $exception->getMessage();
                    }
                });
            }
        });

        $this->assertSame([], $errors, implode('; ', $errors));
        $this->assertCount(2, $results);

        foreach ($results as $index => $result) {
            $this->assertGreaterThan(0, $result['cid']);
            $this->assertSame(sprintf('%024d', $index + 1), $result['id']);
            $this->assertSame($index, $result['found']['index']);
            $this->assertSame(sprintf('%024d', $index + 1), $result['found']['_id']);
            $this->assertTrue($result['pong']);
        }
    }
}
