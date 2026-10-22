<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Lock;

use DomainException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use PDO;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Runtime;
use Swoolefy\Library\Db\Mysql;
use Swoolefy\Library\Db\Pgsql;
use Swoolefy\Library\Lock\MySQLMutex;
use Swoolefy\Library\Lock\PostgreSQLMutex;

/**
 * MySQLMutex / PostgreSQLMutex 的 synchronized 协程语义。
 *
 * 需要真实数据库：
 * - MySQL: DB_HOST_NAME / DB_HOST_PORT / DB_HOST_DATABASE / DB_USER_NAME / DB_PASSWORD
 * - PostgreSQL: POSTGRES_HOST / POSTGRES_PORT / POSTGRES_DATABASE / POSTGRES_USER / POSTGRES_PASSWORD
 * 数据库不可达或缺少 PDO 驱动时按 skip 处理。
 */
final class DatabaseLockTest extends TestCase
{
    private const TEST_PREFIX = 'phpunit_lock_';

    private const KEY_PREFIX = 'php-malkusch-lock:';

    /** @var list<string> */
    private array $lockNames = [];

    public static function setUpBeforeClass(): void
    {
        if (!defined('APP_PATH')) {
            define('APP_PATH', dirname(__DIR__) . '/fixtures/app');
        }
    }

    // ------------------------------------------------------------------
    // MySQL
    // ------------------------------------------------------------------

    public function testMysqlSynchronizedRunsCallbackInChildCoroutineAndReleasesLock(): void
    {
        $this->requireMysql();
        $this->enableHooks();
        $name = $this->nextLockName();
        $parentCid = -1;
        $childCid = -1;
        $result = null;

        Coroutine\run(function () use ($name, &$parentCid, &$childCid, &$result): void {
            $parentCid = Coroutine::getCid();
            $result = (new MySQLMutex($this->mysql(), $name, 2.0))->synchronized(
                function () use (&$childCid): string {
                    $childCid = Coroutine::getCid();

                    return 'mysql-result';
                }
            );

            $this->assertMysqlLockIsFree(self::KEY_PREFIX . $name);
        });

        $this->assertSame('mysql-result', $result);
        $this->assertGreaterThan(0, $parentCid);
        $this->assertGreaterThan(0, $childCid);
        $this->assertNotSame($parentCid, $childCid, '回调应在子协程中执行');
    }

    public function testMysqlAcquireTimeoutRaisesLockAcquireTimeoutException(): void
    {
        $this->requireMysql();
        $this->enableHooks();
        $name = $this->nextLockName();
        $key = self::KEY_PREFIX . $name;
        $holder = $this->acquireMysqlLockOnNewSession($key);
        $executed = false;
        $caught = null;
        $elapsed = 0.0;

        try {
            Coroutine\run(function () use ($name, &$executed, &$caught, &$elapsed): void {
                $startedAt = microtime(true);
                try {
                    (new MySQLMutex($this->mysql(), $name, 1.0))->synchronized(
                        static function () use (&$executed): void {
                            $executed = true;
                        }
                    );
                } catch (LockAcquireTimeoutException $e) {
                    $caught = $e;
                } finally {
                    $elapsed = microtime(true) - $startedAt;
                }
            });
        } finally {
            $this->releaseMysqlLock($holder, $key);
        }

        $this->assertInstanceOf(LockAcquireTimeoutException::class, $caught);
        $this->assertFalse($executed, '未获得锁时临界区不得执行');
        $this->assertGreaterThanOrEqual(0.95, $elapsed);
    }

    public function testMysqlBusinessExceptionIsPropagatedAndLockReleased(): void
    {
        $this->requireMysql();
        $this->enableHooks();
        $name = $this->nextLockName();
        $key = self::KEY_PREFIX . $name;
        $businessException = new DomainException('mysql-biz');
        $caught = null;

        Coroutine\run(function () use ($name, $businessException, &$caught): void {
            try {
                (new MySQLMutex($this->mysql(), $name, 2.0))->synchronized(
                    static function () use ($businessException): void {
                        throw $businessException;
                    }
                );
            } catch (\Throwable $e) {
                $caught = $e;
            }

            $this->assertMysqlLockIsFree(self::KEY_PREFIX . $name);
        });

        $this->assertSame($businessException, $caught, '业务异常应原样透传到调用方协程');
    }

    public function testMysqlSynchronizedFallsBackToInlineExecutionOutsideCoroutine(): void
    {
        $this->requireMysql();
        $name = $this->nextLockName();
        $key = self::KEY_PREFIX . $name;
        $callbackCid = null;

        $this->assertSame(-1, Coroutine::getCid(), '用例应运行在非协程环境');

        $result = (new MySQLMutex($this->mysql(), $name, 2.0))->synchronized(
            static function () use (&$callbackCid): string {
                $callbackCid = Coroutine::getCid();

                return 'mysql-inline';
            }
        );

        $this->assertSame('mysql-inline', $result);
        $this->assertSame(-1, $callbackCid, '非协程环境应回退到上游同步实现');
        $this->assertMysqlLockIsFree($key);
    }

    // ------------------------------------------------------------------
    // PostgreSQL
    // ------------------------------------------------------------------

    public function testPostgresqlSynchronizedRunsCallbackInChildCoroutineAndReleasesLock(): void
    {
        $this->requirePgsql();
        $this->enableHooks();
        $name = $this->nextLockName();
        $parentCid = -1;
        $childCid = -1;
        $result = null;

        Coroutine\run(function () use ($name, &$parentCid, &$childCid, &$result): void {
            $parentCid = Coroutine::getCid();
            $result = (new PostgreSQLMutex($this->pgsql(), $name, 2.0))->synchronized(
                function () use (&$childCid): string {
                    $childCid = Coroutine::getCid();

                    return 'pg-result';
                }
            );
        });

        $this->assertSame('pg-result', $result);
        $this->assertGreaterThan(0, $parentCid);
        $this->assertGreaterThan(0, $childCid);
        $this->assertNotSame($parentCid, $childCid, '回调应在子协程中执行');

        $this->assertSame('acquired-after-release', (new PostgreSQLMutex($this->pgsql(), $name, 0.3))->synchronized(
            static fn (): string => 'acquired-after-release'
        ));
    }

    public function testPostgresqlSynchronizedSerializesCoroutines(): void
    {
        $this->requirePgsql();
        $this->enableHooks();
        $name = $this->nextLockName();
        $events = [];
        $startedAt = microtime(true);

        Coroutine\run(function () use ($name, &$events): void {
            $cids = [];
            foreach (['a', 'b'] as $tag) {
                $cids[] = Coroutine::create(function () use ($tag, $name, &$events): void {
                    (new PostgreSQLMutex($this->pgsql(), $name, 5.0))->synchronized(
                        function () use ($tag, &$events): void {
                            $events[] = 'in-' . $tag;
                            usleep(150000);
                            $events[] = 'out-' . $tag;
                        }
                    );
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

    public function testPostgresqlAcquireTimeoutRaisesLockAcquireTimeoutException(): void
    {
        $this->requirePgsql();
        $this->enableHooks();
        $name = $this->nextLockName();
        $executed = false;
        $elapsed = 0.0;

        Coroutine\run(function () use ($name, &$executed, &$elapsed): void {
            $holderReady = new Channel(1);
            $holderRelease = new Channel(1);
            $holderCid = Coroutine::create(function () use ($name, $holderReady, $holderRelease): void {
                (new PostgreSQLMutex($this->pgsql(), $name, 2.0))->synchronized(
                    function () use ($holderReady, $holderRelease): void {
                        $holderReady->push(true);
                        $holderRelease->pop(-1);
                    }
                );
            });

            $this->assertTrue($holderReady->pop(3.0) === true, '持锁协程未在预期时间内进入临界区');
            $startedAt = microtime(true);

            try {
                (new PostgreSQLMutex($this->pgsql(), $name, 0.3))->synchronized(
                    static function () use (&$executed): void {
                        $executed = true;
                    }
                );
                $this->fail('持有锁时应抛出 LockAcquireTimeoutException');
            } catch (LockAcquireTimeoutException) {
                // 预期：有限超时使用 pg_try_advisory_lock + 自旋，等待 0.3 秒后放弃
            } finally {
                $elapsed = microtime(true) - $startedAt;
                $holderRelease->push(true);
            }

            Coroutine::join([$holderCid], 5.0);
        });

        $this->assertFalse($executed, '未获得锁时临界区不得执行');
        $this->assertGreaterThanOrEqual(0.25, $elapsed);
    }

    public function testPostgresqlBusinessExceptionIsPropagatedAndLockReleased(): void
    {
        $this->requirePgsql();
        $this->enableHooks();
        $name = $this->nextLockName();
        $businessException = new DomainException('pg-biz');
        $caught = null;

        Coroutine\run(function () use ($name, $businessException, &$caught): void {
            try {
                (new PostgreSQLMutex($this->pgsql(), $name, 2.0))->synchronized(
                    static function () use ($businessException): void {
                        throw $businessException;
                    }
                );
            } catch (\Throwable $e) {
                $caught = $e;
            }

            $this->assertSame('acquired-after-exception', (new PostgreSQLMutex($this->pgsql(), $name, 0.3))->synchronized(
                static fn (): string => 'acquired-after-exception'
            ));
        });

        $this->assertSame($businessException, $caught, '业务异常应原样透传到调用方协程');
    }

    public function testPostgresqlSynchronizedFallsBackToInlineExecutionOutsideCoroutine(): void
    {
        $this->requirePgsql();
        $name = $this->nextLockName();
        $callbackCid = null;

        $this->assertSame(-1, Coroutine::getCid(), '用例应运行在非协程环境');

        $result = (new PostgreSQLMutex($this->pgsql(), $name, 2.0))->synchronized(
            static function () use (&$callbackCid): string {
                $callbackCid = Coroutine::getCid();

                return 'pg-inline';
            }
        );

        $this->assertSame('pg-inline', $result);
        $this->assertSame(-1, $callbackCid, '非协程环境应回退到上游同步实现');
        $this->assertSame('acquired-after-inline', (new PostgreSQLMutex($this->pgsql(), $name, 0.3))->synchronized(
            static fn (): string => 'acquired-after-inline'
        ));
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

    private function enableHooks(): void
    {
        if (!class_exists(Runtime::class)) {
            self::markTestSkipped('Swoole is required for coroutine lock tests');
        }

        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    }

    private function requireMysql(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole is required for lock tests');
        }

        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql is required for MySQL lock tests');
        }

        $config = $this->mysqlConfig();

        try {
            new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $config['hostname'], $config['hostport'], $config['database']),
                $config['username'],
                $config['password'],
                [PDO::ATTR_TIMEOUT => 1, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable) {
            self::markTestSkipped(sprintf(
                'MySQL at %s:%s is unreachable',
                $config['hostname'],
                $config['hostport']
            ));
        }
    }

    private function requirePgsql(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole is required for lock tests');
        }

        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql is required for PostgreSQL lock tests');
        }

        $config = $this->pgsqlConfig();

        try {
            new PDO(
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s;connect_timeout=1',
                    $config['hostname'],
                    $config['hostport'],
                    $config['database']
                ),
                $config['username'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable) {
            self::markTestSkipped(sprintf(
                'PostgreSQL at %s:%s is unreachable',
                $config['hostname'],
                $config['hostport']
            ));
        }
    }

    private function mysql(): Mysql
    {
        $mysql = new Mysql($this->mysqlConfig());
        $mysql->connect([], false);

        return $mysql;
    }

    private function pgsql(): Pgsql
    {
        $pgsql = new Pgsql($this->pgsqlConfig());
        $pgsql->connect([], false);

        return $pgsql;
    }

    private function mysqlConfig(): array
    {
        return [
            'type' => 'mysql',
            'hostname' => $this->env('DB_HOST_NAME', '127.0.0.1'),
            'hostport' => $this->env('DB_HOST_PORT', '3306'),
            'database' => $this->env('DB_HOST_DATABASE', 'test'),
            'username' => $this->env('DB_USER_NAME', 'root'),
            'password' => $this->env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'debug' => 0,
            'print_sql' => 0,
        ];
    }

    private function pgsqlConfig(): array
    {
        return [
            'type' => 'pgsql',
            'hostname' => $this->env('POSTGRES_HOST', '127.0.0.1'),
            'hostport' => $this->env('POSTGRES_PORT', '5432'),
            'database' => $this->env('POSTGRES_DATABASE', 'test'),
            'username' => $this->env('POSTGRES_USER', 'postgres'),
            'password' => $this->env('POSTGRES_PASSWORD', ''),
            'debug' => 0,
            'print_sql' => 0,
        ];
    }

    private function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    private function acquireMysqlLockOnNewSession(string $key): PDO
    {
        $pdo = $this->mysql()->getPdo();
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$key]);
        $this->assertSame('1', (string) $statement->fetchColumn(), '独立会话应能立即获得空闲的命名锁');

        return $pdo;
    }

    private function releaseMysqlLock(PDO $pdo, string $key): void
    {
        $statement = $pdo->prepare('DO RELEASE_LOCK(?)');
        $statement->execute([$key]);
    }

    private function assertMysqlLockIsFree(string $key): void
    {
        $pdo = $this->acquireMysqlLockOnNewSession($key);
        $this->releaseMysqlLock($pdo, $key);
    }
}
