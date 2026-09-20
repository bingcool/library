<?php

namespace Swoolefy\Library\Tests\Db;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Db\SqlRetryPolicy;

class SqlRetryPolicyTest extends TestCase
{
    public function testPlainSelectIsRetryable(): void
    {
        $sql = 'SELECT * FROM user WHERE id = ?';
        $this->assertSame(SqlRetryPolicy::TYPE_SELECT, SqlRetryPolicy::classify($sql));
        $this->assertTrue(SqlRetryPolicy::isRetryable($sql));
    }

    public function testShowAndDescribeAreRetryable(): void
    {
        $this->assertTrue(SqlRetryPolicy::isRetryable('SHOW FULL COLUMNS FROM `user`'));
        $this->assertTrue(SqlRetryPolicy::isRetryable('DESC user'));
        $this->assertTrue(SqlRetryPolicy::isRetryable('DESCRIBE user'));
        $this->assertSame(SqlRetryPolicy::TYPE_SHOW, SqlRetryPolicy::classify('show tables'));
    }

    public function testLeadingCommentSelectIsRetryable(): void
    {
        $sql = "/* x */\n-- y\n# z\n(SELECT 1)";
        $this->assertSame(SqlRetryPolicy::TYPE_SELECT, SqlRetryPolicy::classify($sql));
        $this->assertTrue(SqlRetryPolicy::isRetryable($sql));
    }

    public function testSelectLocksAreNotRetryable(): void
    {
        $this->assertFalse(SqlRetryPolicy::isRetryable('SELECT * FROM account WHERE id = ? FOR UPDATE'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('SELECT * FROM user WHERE id = ? LOCK IN SHARE MODE'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('SELECT * FROM user WHERE id = ? FOR SHARE'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('SELECT * FROM t FOR NO KEY UPDATE'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('SELECT a FROM t INTO OUTFILE \'/tmp/a.csv\''));
        $this->assertSame(SqlRetryPolicy::TYPE_SELECT_LOCK, SqlRetryPolicy::classify('SELECT * FROM t FOR UPDATE'));
    }

    public function testWritesAreNeverRetryable(): void
    {
        $this->assertFalse(SqlRetryPolicy::isRetryable('INSERT INTO user (name) VALUES (?)'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('INSERT INTO user SELECT * FROM tmp'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('UPDATE account SET balance = balance - 100 WHERE id = ?'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('DELETE FROM user WHERE id = ?'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('REPLACE INTO user (id) VALUES (1)'));
        $this->assertSame(SqlRetryPolicy::TYPE_INSERT, SqlRetryPolicy::classify('insert into t values (1)'));
        $this->assertSame(SqlRetryPolicy::TYPE_UPDATE, SqlRetryPolicy::classify('Update t SET a=1'));
    }

    public function testWithAndExplainAreNotRetryable(): void
    {
        $this->assertFalse(SqlRetryPolicy::isRetryable('WITH cte AS (SELECT 1) SELECT * FROM cte'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('WITH cte AS (SELECT 1) INSERT INTO t SELECT * FROM cte'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('EXPLAIN SELECT * FROM t'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('EXPLAIN ANALYZE UPDATE t SET a=1'));
    }

    public function testDdlCallAndUnknownAreNotRetryable(): void
    {
        $this->assertFalse(SqlRetryPolicy::isRetryable('CREATE TABLE t (id int)'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('ALTER TABLE t ADD COLUMN a int'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('DROP TABLE t'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('TRUNCATE TABLE t'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('CALL create_order(?)'));
        $this->assertFalse(SqlRetryPolicy::isRetryable('SET names utf8mb4'));
        $this->assertSame(SqlRetryPolicy::TYPE_UNKNOWN, SqlRetryPolicy::classify('PRAGMA table_info(t)'));
    }

    public function testInTransactionNeverRetries(): void
    {
        $this->assertFalse(SqlRetryPolicy::isRetryable('SELECT 1', true));
    }
}
