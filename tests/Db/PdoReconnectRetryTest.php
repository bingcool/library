<?php

namespace Swoolefy\Library\Tests\Db;

use PHPUnit\Framework\TestCase;

class PdoReconnectRetryTest extends TestCase
{
    public function testSelectIsReplayedOnceAfterGoneAway(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->failTimes = 1;

        $rows = $db->query('SELECT 1');

        $this->assertSame([['1' => 1]], $this->normalizeSqliteSelectOne($rows));
        $this->assertCount(2, $db->executions);
        $this->assertGreaterThanOrEqual(2, $db->connectCount);
        $this->assertSame('SELECT 1', $db->executions[0]);
        $this->assertSame('SELECT 1', $db->executions[1]);
    }

    public function testInsertIsNotReplayed(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->failTimes = 1;

        $this->expectException(\PDOException::class);
        try {
            $db->execute('INSERT INTO user (name) VALUES (?)', ['a']);
        } finally {
            $this->assertCount(1, $db->executions);
            $this->assertGreaterThanOrEqual(2, $db->connectCount);
        }
    }

    public function testUpdateAndDeleteAreNotReplayed(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->failTimes = 1;
        try {
            $db->query('UPDATE account SET balance = balance - 100 WHERE id = 1');
            $this->fail('update should throw');
        } catch (\PDOException $e) {
            $this->assertCount(1, $db->executions);
        }

        $db->failTimes = 1;
        $before = count($db->executions);
        try {
            $db->execute('DELETE FROM user WHERE id = 1');
            $this->fail('delete should throw');
        } catch (\PDOException $e) {
            $this->assertCount($before + 1, $db->executions);
        }
    }

    public function testSelectForUpdateIsNotReplayed(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->failTimes = 1;

        $this->expectException(\PDOException::class);
        try {
            $db->query('SELECT * FROM account WHERE id = 1 FOR UPDATE');
        } finally {
            $this->assertCount(1, $db->executions);
        }
    }

    public function testWithSelectIsNotReplayed(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->failTimes = 1;

        $this->expectException(\PDOException::class);
        try {
            $db->query('WITH cte AS (SELECT 1 AS id) SELECT * FROM cte');
        } finally {
            $this->assertCount(1, $db->executions);
        }
    }

    public function testTransactionSelectIsNotReplayedAndConnectionIsClosed(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->connect();
        $db->beginTransaction();
        $this->assertTrue($db->isEnableTransaction());
        $db->failTimes = 1;

        $this->expectException(\PDOException::class);
        try {
            $db->query('SELECT 1');
        } finally {
            $this->assertCount(1, $db->executions);
            $this->assertFalse($db->isEnableTransaction());
        }
    }

    public function testDeadlockDoesNotReconnectOrReplay(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->connect();
        $connectCount = $db->connectCount;
        $db->failTimes = 1;
        $db->failWith = PdoRetryConnectionStub::deadlock();

        $this->expectException(\PDOException::class);
        try {
            $db->query('SELECT 1');
        } finally {
            $this->assertCount(1, $db->executions);
            $this->assertSame($connectCount, $db->connectCount);
        }
    }

    public function testSyntaxErrorDoesNotReconnect(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->connect();
        $connectCount = $db->connectCount;
        $db->failTimes = 1;
        $db->failWith = PdoRetryConnectionStub::syntaxError();

        $this->expectException(\PDOException::class);
        try {
            $db->query('SELEC 1');
        } finally {
            $this->assertSame($connectCount, $db->connectCount);
            $this->assertCount(1, $db->executions);
        }
    }

    public function testSelectReplayAtMostOnce(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->failTimes = 2;

        $this->expectException(\PDOException::class);
        try {
            $db->query('SELECT 1');
        } finally {
            $this->assertCount(2, $db->executions);
        }
    }

    public function testCommentedSelectIsReplayed(): void
    {
        $db = new PdoRetryConnectionStub();
        $db->failTimes = 1;

        $db->query('/* x */ SELECT 1');

        $this->assertCount(2, $db->executions);
    }

    public function testRetryDisabledHealsButDoesNotReplaySelect(): void
    {
        $db = new PdoRetryConnectionStub(['retry' => ['enabled' => false]]);
        $db->failTimes = 1;

        $this->expectException(\PDOException::class);
        try {
            $db->query('SELECT 1');
        } finally {
            $this->assertCount(1, $db->executions);
            $this->assertGreaterThanOrEqual(2, $db->connectCount);
        }
    }

    /**
     * @param array $rows
     * @return array
     */
    private function normalizeSqliteSelectOne(array $rows): array
    {
        if ($rows === [['1' => 1]] || $rows === [[1 => 1]] || $rows === [['1' => '1']]) {
            return [['1' => 1]];
        }

        $first = $rows[0] ?? [];
        $value = (int)reset($first);

        return [['1' => $value]];
    }
}
