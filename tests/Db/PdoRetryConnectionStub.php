<?php

namespace Swoolefy\Library\Tests\Db;

use PDO;
use PDOStatement;
use Swoolefy\Library\Db\Sqlite;

final class PdoRetryConnectionStub extends Sqlite
{
    public array $executions = [];

    public int $connectCount = 0;

    public int $failTimes = 0;

    public ?\PDOException $failWith = null;

    public function __construct(array $config = [])
    {
        parent::__construct(array_merge([
            'type' => 'sqlite',
            'database' => ':memory:',
            'debug' => 0,
            'print_sql' => 0,
        ], $config));
    }

    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        $this->connectCount++;

        return parent::createPdo($dsn, $username, $password, $params);
    }

    protected function performPreparedExecute(string $sql, array $bindParams): PDOStatement
    {
        $this->executions[] = $sql;
        if ($this->failTimes > 0) {
            $this->failTimes--;
            throw $this->failWith ?? self::goneAway();
        }

        return parent::performPreparedExecute($sql, $bindParams);
    }

    public static function goneAway(): \PDOException
    {
        $exception = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $exception->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];

        return $exception;
    }

    public static function deadlock(): \PDOException
    {
        $exception = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        $exception->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

        return $exception;
    }

    public static function syntaxError(): \PDOException
    {
        $exception = new \PDOException('SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax');
        $exception->errorInfo = ['42000', 1064, 'You have an error in your SQL syntax'];

        return $exception;
    }
}
