<?php

namespace Swoolefy\Library\Tests\Db;

use PDO;
use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Db\Interceptor\TenantLineInterceptor;
use Swoolefy\Library\Db\PDOConnection;
use Swoolefy\Library\Db\Query;
use Swoolefy\Library\Db\Sqlite;
use Swoolefy\Library\Exception\DbException;
use Swoolefy\Library\Tests\Db\Stub\Article;
use Swoolefy\Library\Tests\Db\Stub\FixedTenantHandler;

/**
 * 对应 docs/library-6.x-Db-模块Bug审查.md 中 DB-1 ~ DB-14 的回归用例
 */
class DbBugFixTest extends TestCase
{
    protected function setUp(): void
    {
        PDOConnection::clearGlobalSqlInterceptors();
    }

    private function newDb(): Sqlite
    {
        $db = new Sqlite(['type' => 'sqlite', 'database' => ':memory:', 'debug' => 0]);
        $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, a TEXT, b TEXT, n INTEGER, ts timestamp)');
        return $db;
    }

    /** DB-1 */
    public function testTransactionXaUsesSameXidForAllPhases(): void
    {
        $db = new XaRecordingSqlite(['type' => 'sqlite', 'database' => ':memory:', 'debug' => 0]);

        $result = $db->transactionXa(fn() => 'ok');

        $this->assertSame('ok', $result);
        $this->assertSame(['start', 'prepare', 'commit'], array_column($db->xaCalls, 0));
        $this->assertCount(1, array_unique(array_column($db->xaCalls, 1)));
    }

    /** DB-1 */
    public function testTransactionXaRollsBackWithSameXid(): void
    {
        $db = new XaRecordingSqlite(['type' => 'sqlite', 'database' => ':memory:', 'debug' => 0]);

        try {
            $db->transactionXa(function () {
                throw new \RuntimeException('biz');
            });
            $this->fail('should throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('biz', $e->getMessage());
        }

        $this->assertSame(['start', 'rollback'], array_column($db->xaCalls, 0));
        $this->assertCount(1, array_unique(array_column($db->xaCalls, 1)));
    }

    /** DB-11 */
    public function testXaNotSupportedDriverThrows(): void
    {
        $this->expectException(DbException::class);
        $this->newDb()->transactionXa(fn() => null);
    }

    /** DB-2 */
    public function testCommitCallbackDoesNotLeakAfterRollback(): void
    {
        $db = $this->newDb();
        $fired = [];

        $db->beginTransaction();
        $db->afterCommitCallback(function () use (&$fired) { $fired[] = 'commit-cb'; });
        $db->afterRollbackCallback(function () use (&$fired) { $fired[] = 'rollback-cb'; });
        $db->rollback();

        $db->beginTransaction();
        $db->commit();

        $this->assertSame(['rollback-cb'], $fired);
    }

    /** DB-2 */
    public function testRollbackCallbackDoesNotLeakAfterCommit(): void
    {
        $db = $this->newDb();
        $fired = [];

        $db->beginTransaction();
        $db->afterCommitCallback(function () use (&$fired) { $fired[] = 'commit-cb'; });
        $db->afterRollbackCallback(function () use (&$fired) { $fired[] = 'rollback-cb'; });
        $db->commit();

        $db->beginTransaction();
        $db->rollback();

        $this->assertSame(['commit-cb'], $fired);
    }

    /** DB-2：不支持 savepoint 时内层 rollback，外层 commit 改为回滚，只执行回滚回调 */
    public function testNestedRollbackWithoutSavepointRunsOnlyRollbackCallbacks(): void
    {
        $db = new NoSavepointSqlite(['type' => 'sqlite', 'database' => ':memory:', 'debug' => 0]);
        $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, a TEXT)');
        $fired = [];

        $db->beginTransaction();
        $db->execute("INSERT INTO t (a) VALUES ('x')");
        $db->beginTransaction();
        $db->afterCommitCallback(function () use (&$fired) { $fired[] = 'commit-cb'; });
        $db->afterRollbackCallback(function () use (&$fired) { $fired[] = 'rollback-cb'; });
        $db->rollback();
        $db->commit();

        $this->assertSame(['rollback-cb'], $fired);
        $this->assertFalse($db->isEnableTransaction());
        $this->assertSame(0, (int)$db->createCommand('SELECT COUNT(*) FROM t')->queryScalar());
    }

    /** DB-3 */
    public function testFailedCommitAndRollbackResetTransactionState(): void
    {
        $db = new DeadTransactionSqlite(['type' => 'sqlite', 'database' => ':memory:', 'debug' => 0]);
        $db->beginTransaction();

        try {
            $db->commit();
            $this->fail('commit should throw');
        } catch (\PDOException $e) {
        }
        $this->assertFalse($db->isEnableTransaction());

        $db->beginTransaction();
        try {
            $db->rollback();
            $this->fail('rollback should throw');
        } catch (\PDOException $e) {
        }
        $this->assertFalse($db->isEnableTransaction());
    }

    /** DB-4 */
    public function testCursorIsNotAffectedByOtherQueriesDuringIteration(): void
    {
        $db = $this->newDb();
        $db->execute("INSERT INTO t (a) VALUES ('1'), ('2'), ('3')");

        $rows = [];
        foreach ($db->cursor('SELECT a FROM t ORDER BY id', []) as $row) {
            $rows[] = $row['a'];
            $db->query("SELECT 'other' AS a");
        }

        $this->assertSame(['1', '2', '3'], $rows);
    }

    /** DB-13 */
    public function testCursorDoesNotStopOnFalsyColumnValue(): void
    {
        $db = $this->newDb();
        $db->execute("INSERT INTO t (n) VALUES (1), (0), (2)");

        $values = iterator_to_array($db->cursor('SELECT n FROM t ORDER BY id', [], PDO::FETCH_COLUMN), false);

        $this->assertSame([1, 0, 2], array_map('intval', $values));
    }

    /** DB-5 */
    public function testMultiInsertAlignsColumnsRegardlessOfKeyOrder(): void
    {
        $db = $this->newDb();
        $db->multiInsert('t', [['a' => 'A0', 'b' => 'B0'], ['b' => 'B1', 'a' => 'A1']]);

        $rows = $db->query('SELECT a, b FROM t ORDER BY id');

        $this->assertSame([['a' => 'A0', 'b' => 'B0'], ['a' => 'A1', 'b' => 'B1']], $rows);
    }

    /** DB-5 */
    public function testMultiInsertRejectsRowsWithDifferentFields(): void
    {
        $this->expectException(DbException::class);
        $this->newDb()->multiInsert('t', [['a' => 'A0', 'b' => 'B0'], ['a' => 'A1']]);
    }

    /** DB-6 */
    public function testBatchInsertKeepsTimestampAndNull(): void
    {
        $db = $this->newDb();
        $db->batchInsert('t', ['a', 'n', 'ts'], [
            ['x', 5, '2026-10-14 12:00:00'],
            [null, null, null],
        ]);

        $rows = $db->query('SELECT a, n, ts FROM t ORDER BY id');

        $this->assertSame('2026-10-14 12:00:00', $rows[0]['ts']);
        $this->assertSame(5, (int)$rows[0]['n']);
        $this->assertSame(['a' => null, 'n' => null, 'ts' => null], $rows[1]);
    }

    /** DB-8 */
    public function testCommitThrowsAfterTransactionLostByConnectionError(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->beginTransaction();
        $db->failTimes = 1;

        try {
            $db->query('SELECT 1');
            $this->fail('should throw');
        } catch (\PDOException $e) {
            // 业务吞掉异常继续执行
        }

        $this->expectException(DbException::class);
        $db->commit();
    }

    /** DB-8 */
    public function testRollbackAfterTransactionLostClearsBrokenFlag(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->beginTransaction();
        $db->failTimes = 1;

        try {
            $db->query('SELECT 1');
        } catch (\PDOException $e) {
            $db->rollback();
        }

        $db->beginTransaction();
        $db->commit();
        $this->assertFalse($db->isEnableTransaction());
    }

    /** DB-9 */
    public function testBeginRetryDoesNotConsumeSelectReplayQuota(): void
    {
        $db = new FlakyBeginSqlite();
        $db->beginFailTimes = 5;

        try {
            $db->beginTransaction();
            $this->fail('begin should throw after retries');
        } catch (\PDOException $e) {
        }

        $db->failTimes = 1;
        $db->query('SELECT 1');

        $this->assertCount(2, $db->executions);
    }

    /** DB-10 */
    public function testPersistenceUpdateRequiresPrimaryKeyValue(): void
    {
        $db = new Sqlite(['type' => 'sqlite', 'database' => ':memory:', 'debug' => 0]);
        $db->execute('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
        $article = new Article($db);
        $article->title = 'no pk';

        $reload = new \ReflectionMethod($article, 'reloadByPrimaryKey');
        $this->assertNull($reload->invoke($article));

        $apply = new \ReflectionMethod($article, 'applyPersistenceWhere');
        $this->expectException(DbException::class);
        $apply->invoke($article, (new Query($db))->table('articles'));
    }

    /** DB-12 */
    public function testSqliteGetFieldsRejectsUnsafeTableName(): void
    {
        $db = $this->newDb();
        $this->assertArrayHasKey('a', $db->getFields('`t`'));

        $this->expectException(DbException::class);
        $db->getFields("t') ; DROP TABLE t; --");
    }

    /** DB-14 */
    public function testGetRealSqlReplacesExactPlaceholders(): void
    {
        $db = $this->newDb();

        $this->assertSame(
            "SELECT * FROM t WHERE a = 'x' AND ab = 'y'",
            $db->getRealSql('SELECT * FROM t WHERE a = :a AND ab = :ab', [':a' => 'x', ':ab' => 'y'])
        );
        $this->assertSame(
            "SELECT 1",
            $db->getRealSql('SELECT 1', ['unused'])
        );
    }

    /** DB-7 */
    public function testTenantInterceptorWarnsWhenUpdateNotRecognized(): void
    {
        $db = $this->newDb();
        $interceptor = new TenantLineInterceptor(new FixedTenantHandler('1'));
        $sql = 'UPDATE t, t2 SET t.a = 1';
        $bind = [];

        $interceptor->beforeExecute($db, $sql, $bind);

        $actions = array_column($db->getLastLogs(), 'action');
        $this->assertContains('tenant interceptor skipped', $actions);
    }
}

class NoSavepointSqlite extends Sqlite
{
    protected function supportSavepoint(): bool
    {
        return false;
    }
}

class XaRecordingSqlite extends Sqlite
{
    public array $xaCalls = [];

    public function startTransXa(string $xid)
    {
        $this->xaCalls[] = ['start', $xid];
    }

    public function prepareXa(string $xid)
    {
        $this->xaCalls[] = ['prepare', $xid];
    }

    public function commitXa(string $xid)
    {
        $this->xaCalls[] = ['commit', $xid];
    }

    public function rollbackXa(string $xid)
    {
        $this->xaCalls[] = ['rollback', $xid];
    }
}

class DeadTransactionPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        throw PdoRetryConnectionStub::goneAway();
    }

    public function rollBack(): bool
    {
        throw PdoRetryConnectionStub::goneAway();
    }
}

class DeadTransactionSqlite extends Sqlite
{
    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return new DeadTransactionPdo();
    }
}

class FlakyBeginPdo extends PDO
{
    public function __construct(private FlakyBeginSqlite $owner)
    {
        parent::__construct('sqlite::memory:');
    }

    public function beginTransaction(): bool
    {
        if ($this->owner->beginFailTimes > 0) {
            $this->owner->beginFailTimes--;
            throw PdoRetryConnectionStub::goneAway();
        }
        return parent::beginTransaction();
    }
}

class FlakyBeginSqlite extends Sqlite
{
    public int $beginFailTimes = 0;

    public int $failTimes = 0;

    public array $executions = [];

    public function __construct()
    {
        parent::__construct(['type' => 'sqlite', 'database' => ':memory:', 'debug' => 0]);
    }

    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        $pdo = new FlakyBeginPdo($this);
        foreach ($params as $key => $value) {
            $pdo->setAttribute($key, $value);
        }
        return $pdo;
    }

    protected function performPreparedExecute(string $sql, array $bindParams): \PDOStatement
    {
        $this->executions[] = $sql;
        if ($this->failTimes > 0) {
            $this->failTimes--;
            throw PdoRetryConnectionStub::goneAway();
        }

        return parent::performPreparedExecute($sql, $bindParams);
    }
}
