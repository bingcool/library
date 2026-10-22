<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Lock;

use DomainException;
use InvalidArgumentException;
use Malkusch\Lock\Exception\ExecutionOutsideLockException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClient;
use Swoole\Coroutine;
use Swoole\Runtime;
use Swoolefy\Library\Db\Mysql;
use Swoolefy\Library\Db\Pgsql;
use Swoolefy\Library\Lock\MySQLMutex;
use Swoolefy\Library\Lock\PostgreSQLMutex;
use Swoolefy\Library\Lock\RedisMutex;
use Swoolefy\Library\Redis\Predis as SwoolefyPredis;
use Swoolefy\Library\Redis\Redis as SwoolefyRedis;

/**
 * Lock 模块 3.1.x 回归测试。
 *
 * 无 ext-redis / predis / Swoole 或 Redis 不可达时按 skip 处理。
 * Redis 地址通过 REDIS_HOST / REDIS_PORT 指定（容器内默认 192.168.60.83:6379）。
 */
final class LockModuleTest extends TestCase
{
    private const KEY_PREFIX = 'php-malkusch-lock:';

    private const TEST_PREFIX = 'phpunit_lock_';

    /** @var list<string> 本用例创建的锁名，tearDown 时清理 */
    private array $lockNames = [];

    public static function setUpBeforeClass(): void
    {
        // goApp() 初始化 EventController 需要读取 APP_PATH/Protocol/conf.php
        if (!defined('APP_PATH')) {
            define('APP_PATH', dirname(__DIR__) . '/fixtures/app');
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupLockKeys();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 构造校验（不依赖服务端）
    // ------------------------------------------------------------------

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lock name must not be empty');

        new RedisMutex(new \stdClass(), '   ');
    }

    #[DataProvider('invalidAcquireTimeoutProvider')]
    public function testNonPositiveAcquireTimeoutIsRejected(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('acquire timeout');

        new RedisMutex(new \stdClass(), 'name', $timeout);
    }

    public static function invalidAcquireTimeoutProvider(): array
    {
        return [
            'zero' => [0.0],
            'negative' => [-1.0],
            'nan' => [NAN],
        ];
    }

    #[DataProvider('invalidLeaseTimeoutProvider')]
    public function testInvalidLeaseTimeoutIsRejected(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lease timeout');

        new RedisMutex(new \stdClass(), 'name', 3.0, $timeout);
    }

    public static function invalidLeaseTimeoutProvider(): array
    {
        return [
            'zero' => [0.0],
            'negative' => [-3.0],
            'infinite' => [INF],
            'nan' => [NAN],
        ];
    }

    public function testUnsupportedClientIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Redis client');

        new RedisMutex(new \stdClass(), 'name');
    }

    public function testMysqlMutexRequiresConnectedPdo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not established');

        new MySQLMutex(new Mysql(), 'name');
    }

    public function testMysqlMutexRejectsNegativeTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('acquire timeout');

        new MySQLMutex(new Mysql(), 'name', -0.1);
    }

    public function testPostgresqlMutexRequiresConnectedPdo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not established');

        new PostgreSQLMutex(new Pgsql(), 'name');
    }

    public function testPostgresqlMutexRejectsInfiniteTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('acquire timeout');

        new PostgreSQLMutex(new Pgsql(), 'name', INF);
    }

    // ------------------------------------------------------------------
    // Redis 锁行为（需要真实 Redis）
    // ------------------------------------------------------------------

    public function testLockKeyNameTtlAndRelease(): void
    {
        $this->requireRedis();
        $name = $this->nextLockName();
        $client = $this->phpredis();

        $result = (new RedisMutex($client, $name, 2.0, 7.0))->synchronized(function () use ($client, $name) {
            $key = self::KEY_PREFIX . $name;
            $this->assertSame([$key], $client->keys(self::KEY_PREFIX . $name));
            $ttl = $client->ttl($key);
            $this->assertGreaterThanOrEqual(6, $ttl);
            $this->assertLessThanOrEqual(7, $ttl);
            $this->assertSame(64, strlen((string) $client->get($key)));

            return 'critical-result';
        });

        $this->assertSame('critical-result', $result);
        $this->assertSame([], $client->keys(self::KEY_PREFIX . $name));
    }

    public function testSwoolefyRedisWrapperIsUnwrapped(): void
    {
        $this->requireRedis();
        $name = $this->nextLockName();
        $wrapper = (new SwoolefyRedis())->connect($this->host(), $this->port(), 1.0);

        $result = (new RedisMutex($wrapper, $name, 2.0, 30.0))->synchronized(
            static fn (): string => 'wrapper-ok'
        );

        $this->assertSame('wrapper-ok', $result);
        $this->assertSame([], $wrapper->keys(self::KEY_PREFIX . $name));
    }

    public function testCoroutinesAreMutuallyExclusive(): void
    {
        $this->requireRedis();
        $name = $this->nextLockName();
        $this->enableHooks();

        $events = [];
        $startedAt = microtime(true);

        Coroutine\run(function () use ($name, &$events): void {
            $cids = [];
            foreach (['a', 'b'] as $tag) {
                $cids[] = Coroutine::create(function () use ($tag, $name, &$events): void {
                    $lock = new RedisMutex($this->phpredis(), $name, 5.0, 30.0);
                    $lock->synchronized(function () use ($tag, &$events): void {
                        $events[] = 'in-' . $tag;
                        usleep(150000);
                        $events[] = 'out-' . $tag;
                    });
                });
            }

            Coroutine::join($cids, 5.0);
        });

        $this->assertCount(4, $events);
        $firstTag = substr($events[0], 3);
        $secondTag = substr($events[2], 3);
        $this->assertStringStartsWith('in-', $events[0]);
        $this->assertSame('out-' . $firstTag, $events[1]);
        $this->assertStringStartsWith('in-', $events[2]);
        $this->assertSame('out-' . $secondTag, $events[3]);
        $this->assertNotSame($firstTag, $secondTag, '两个协程不得同时进入临界区');
        $this->assertGreaterThanOrEqual(0.28, microtime(true) - $startedAt, '临界区应串行执行');
    }

    public function testAcquireTimeoutRaisesLockAcquireTimeoutException(): void
    {
        $this->requireRedis();
        $name = $this->nextLockName();
        $foreign = $this->phpredis();
        $foreign->set(self::KEY_PREFIX . $name, 'held-by-other', ['nx', 'px' => 30000]);

        $executed = false;
        $startedAt = microtime(true);

        try {
            (new RedisMutex($this->phpredis(), $name, 0.3, 30.0))->synchronized(
                static function () use (&$executed): void {
                    $executed = true;
                }
            );
            $this->fail('持有锁时应抛出 LockAcquireTimeoutException');
        } catch (LockAcquireTimeoutException $e) {
            $this->assertStringContainsString('0.3', $e->getMessage());
        }

        $this->assertFalse($executed, '未获得锁时临界区不得执行');
        $this->assertGreaterThanOrEqual(0.2, microtime(true) - $startedAt);
    }

    public function testLeaseExpiryRaisesExecutionOutsideLockException(): void
    {
        $this->requireRedis();
        $name = $this->nextLockName();
        $client = $this->phpredis();

        try {
            (new RedisMutex($client, $name, 2.0, 0.2))->synchronized(static function (): string {
                usleep(400000);

                return 'slow-result';
            });
            $this->fail('临界区超出租期时应抛出 ExecutionOutsideLockException');
        } catch (ExecutionOutsideLockException $e) {
            $this->assertSame('slow-result', $e->getCodeResult());
            $this->assertNull($e->getCodeException());
        }

        $this->assertSame([], $client->keys(self::KEY_PREFIX . $name), '租期越界后仍应按 token 释放锁');
    }

    public function testBusinessExceptionReleasesLock(): void
    {
        $this->requireRedis();
        $name = $this->nextLockName();
        $client = $this->phpredis();

        try {
            (new RedisMutex($client, $name, 2.0, 30.0))->synchronized(static function (): void {
                throw new DomainException('biz');
            });
            $this->fail('业务异常应原样透传');
        } catch (DomainException $e) {
            $this->assertSame('biz', $e->getMessage());
        }

        $this->assertSame([], $client->keys(self::KEY_PREFIX . $name));
    }

    public function testPredisClientAndWrapper(): void
    {
        $this->requirePredis();
        $this->requireRedis();
        $name = $this->nextLockName();

        $native = new PredisClient($this->predisParameters());
        $this->assertSame('predis-native', (new RedisMutex($native, $name, 2.0, 30.0))->synchronized(
            static fn (): string => 'predis-native'
        ));
        $this->assertSame([], $this->flattenKeys($native->keys(self::KEY_PREFIX . $name)));

        $wrapper = new SwoolefyPredis($this->predisParameters());
        $this->assertSame('predis-wrapper', (new RedisMutex($wrapper, $name, 2.0, 30.0))->synchronized(
            static fn (): string => 'predis-wrapper'
        ));
    }

    public function testMixedClientsAreMutuallyExclusive(): void
    {
        $this->requireRedis();
        $this->requirePredis();
        $this->enableHooks();

        $name = $this->nextLockName();
        $holderReady = new Coroutine\Channel(1);
        $holderRelease = new Coroutine\Channel(1);

        Coroutine\run(function () use ($name, $holderReady, $holderRelease): void {
            Coroutine::create(function () use ($name, $holderReady, $holderRelease): void {
                $lock = new RedisMutex($this->phpredis(), $name, 2.0, 30.0);
                $lock->synchronized(function () use ($holderReady, $holderRelease): void {
                    $holderReady->push(true, 2.0);
                    $holderRelease->pop(3.0);
                });
            });

            $this->assertTrue($holderReady->pop(3.0) === true, 'phpredis 未在预期时间内持锁');

            try {
                $predis = new PredisClient($this->predisParameters());
                (new RedisMutex($predis, $name, 0.3, 30.0))->synchronized(static fn (): string => 'should-not-run');
                $this->fail('predis 不应在 phpredis 持锁期间获得同一把锁');
            } catch (LockAcquireTimeoutException) {
                // 预期：跨客户端互斥
            } finally {
                $holderRelease->push(true, 1.0);
            }
        });
    }

    public function testSynchronizedAcceptsInfiniteAcquireTimeout(): void
    {
        $this->requireRedis();
        $name = $this->nextLockName();

        $this->assertSame('ok', (new RedisMutex($this->phpredis(), $name, INF, 30.0))->synchronized(
            static fn (): string => 'ok'
        ));
    }

    // ------------------------------------------------------------------
    // synchronized 协程语义
    // ------------------------------------------------------------------

    public function testSynchronizedRunsCallbackInChildCoroutine(): void
    {
        $this->requireRedis();
        $this->enableHooks();
        $name = $this->nextLockName();
        $parentCid = null;
        $childCid = null;
        $result = null;

        Coroutine\run(function () use ($name, &$parentCid, &$childCid, &$result): void {
            $parentCid = Coroutine::getCid();
            $result = (new RedisMutex($this->phpredis(), $name, 2.0, 30.0))->synchronized(
                function () use (&$childCid): string {
                    $childCid = Coroutine::getCid();

                    return 'child-result';
                }
            );
        });

        $this->assertSame('child-result', $result);
        $this->assertGreaterThan(0, $parentCid);
        $this->assertGreaterThan(0, $childCid);
        $this->assertNotSame($parentCid, $childCid, '回调应在子协程中执行');
    }

    public function testSynchronizedReturnsOnlyAfterLockReleased(): void
    {
        $this->requireRedis();
        $this->enableHooks();
        $name = $this->nextLockName();
        $keyExistsAfterReturn = null;
        $startedAt = microtime(true);

        Coroutine\run(function () use ($name, &$keyExistsAfterReturn): void {
            $client = $this->phpredis();
            (new RedisMutex($client, $name, 2.0, 30.0))->synchronized(static function (): void {
                usleep(200000);
            });
            $keyExistsAfterReturn = $client->exists(self::KEY_PREFIX . $name);
        });

        $this->assertSame(0, $keyExistsAfterReturn, 'synchronized 返回时锁必须已经释放');
        $this->assertGreaterThanOrEqual(0.18, microtime(true) - $startedAt);
    }

    public function testSynchronizedPropagatesBusinessExceptionInCoroutine(): void
    {
        $this->requireRedis();
        $this->enableHooks();
        $name = $this->nextLockName();
        $businessException = new DomainException('coroutine-biz');
        $caught = null;

        Coroutine\run(function () use ($name, $businessException, &$caught): void {
            $client = $this->phpredis();
            try {
                (new RedisMutex($client, $name, 2.0, 30.0))->synchronized(
                    static function () use ($businessException): void {
                        throw $businessException;
                    }
                );
            } catch (\Throwable $e) {
                $caught = $e;
            }

            $this->assertSame([], $client->keys(self::KEY_PREFIX . $name));
        });

        $this->assertSame($businessException, $caught, '业务异常应原样透传到调用方协程');
    }

    public function testSynchronizedLeaseOverrunInCoroutineKeepsCodeResult(): void
    {
        $this->requireRedis();
        $this->enableHooks();
        $name = $this->nextLockName();
        $caught = null;

        Coroutine\run(function () use ($name, &$caught): void {
            try {
                (new RedisMutex($this->phpredis(), $name, 2.0, 0.2))->synchronized(
                    static function (): string {
                        usleep(400000);

                        return 'slow-result';
                    }
                );
            } catch (ExecutionOutsideLockException $e) {
                $caught = $e;
            }
        });

        $this->assertInstanceOf(ExecutionOutsideLockException::class, $caught);
        $this->assertSame('slow-result', $caught->getCodeResult());
        $this->assertNull($caught->getCodeException());
    }

    public function testSynchronizedLeaseOverrunInCoroutineKeepsBusinessException(): void
    {
        $this->requireRedis();
        $this->enableHooks();
        $name = $this->nextLockName();
        $businessException = new DomainException('biz-before-release');
        $caught = null;

        Coroutine\run(function () use ($name, $businessException, &$caught): void {
            try {
                (new RedisMutex($this->phpredis(), $name, 2.0, 0.2))->synchronized(
                    static function () use ($businessException): void {
                        usleep(400000);
                        throw $businessException;
                    }
                );
            } catch (ExecutionOutsideLockException $e) {
                $caught = $e;
            }
        });

        $this->assertInstanceOf(ExecutionOutsideLockException::class, $caught);
        $this->assertNull($caught->getCodeResult());
        $this->assertSame($businessException, $caught->getCodeException());
    }

    public function testSynchronizedKeepsNullAndFalseResultsDistinct(): void
    {
        $this->requireRedis();
        $this->enableHooks();
        $name = $this->nextLockName();
        $results = [];

        Coroutine\run(function () use ($name, &$results): void {
            $lock = new RedisMutex($this->phpredis(), $name, 2.0, 30.0);
            $results[] = $lock->synchronized(static fn () => null);
            $results[] = $lock->synchronized(static fn () => false);
        });

        $this->assertSame([null, false], $results, '回调返回 null/false 不得被误判为通道失败');
    }

    public function testSynchronizedPassesMutexToCallbackForLegacyCode(): void
    {
        $this->requireRedis();
        $this->enableHooks();
        $name = $this->nextLockName();
        $received = null;
        $lock = null;

        Coroutine\run(function () use ($name, &$received, &$lock): void {
            $lock = new RedisMutex($this->phpredis(), $name, 2.0, 30.0);
            $lock->synchronized(function ($mutex) use (&$received): void {
                $received = $mutex;
            });
        });

        $this->assertSame($lock, $received);
    }

    public function testSynchronizedFallsBackToInlineExecutionOutsideCoroutine(): void
    {
        $this->requireRedis();
        $name = $this->nextLockName();
        $callbackCid = null;

        $this->assertSame(-1, Coroutine::getCid(), '用例应运行在非协程环境');

        (new RedisMutex($this->phpredis(), $name, 2.0, 30.0))->synchronized(
            static function () use (&$callbackCid): void {
                $callbackCid = Coroutine::getCid();
            }
        );

        $this->assertSame(-1, $callbackCid, '非协程环境应回退到上游同步实现');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function nextLockName(): string
    {
        $name = self::TEST_PREFIX . bin2hex(random_bytes(6));
        $this->lockNames[] = $name;

        return $name;
    }

    private function cleanupLockKeys(): void
    {
        if ($this->lockNames === [] || !extension_loaded('redis')) {
            return;
        }

        try {
            $client = $this->phpredis();
            foreach ($this->lockNames as $name) {
                $client->del(self::KEY_PREFIX . $name);
            }
        } catch (\Throwable) {
            // Redis 不可达时跳过清理，tearDown 不改变测试结论
        }

        $this->lockNames = [];
    }

    private function enableHooks(): void
    {
        if (!class_exists(Runtime::class)) {
            self::markTestSkipped('Swoole is required for coroutine lock tests');
        }

        // 与 swoolefy Worker 的 hook_flags 保持一致：usleep 让出协程，Lock 的 Loop 才不会阻塞 Worker
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    }

    private function requireRedis(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole is required for lock tests');
        }

        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is required for lock tests');
        }

        $socket = @stream_socket_client($this->endpoint(), $errno, $error, 1.0);
        if ($socket === false) {
            self::markTestSkipped(sprintf('redis at %s is unreachable: %s', $this->endpoint(), $error));
        }

        fclose($socket);
    }

    private function requirePredis(): void
    {
        if (!class_exists(PredisClient::class)) {
            self::markTestSkipped('predis/predis is required for predis lock tests');
        }
    }

    private function phpredis(): \Redis
    {
        $client = new \Redis();
        $client->connect($this->host(), $this->port(), 1.0);

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function predisParameters(): array
    {
        return [
            'scheme' => 'tcp',
            'host' => $this->host(),
            'port' => $this->port(),
            'timeout' => 1.0,
        ];
    }

    /**
     * @param iterable<int|string, mixed> $keys
     * @return list<string>
     */
    private function flattenKeys(iterable $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[] = (string) $key;
        }

        return $result;
    }

    private function host(): string
    {
        return (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
    }

    private function port(): int
    {
        return (int) (getenv('REDIS_PORT') ?: 6379);
    }

    private function endpoint(): string
    {
        return sprintf('tcp://%s:%d', $this->host(), $this->port());
    }
}
