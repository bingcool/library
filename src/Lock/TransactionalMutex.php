<?php
namespace Common\library\Lock;

use Common\Library\Db\PDOConnection;
use InvalidArgumentException;
use malkusch\lock\util\Loop;
use PDO;
use PDOException;

/**
 * +----------------------------------------------------------------------
* | Common library of swoole
* +----------------------------------------------------------------------
* | Licensed ( https://opensource.org/licenses/MIT )
* +----------------------------------------------------------------------
* | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
* +----------------------------------------------------------------------
 */

class TransactionalMutex extends \malkusch\lock\mutex\TransactionalMutex
{
    /**
     * @var \PDO $pdo The PDO.
     */
    protected $pdo;

    /**
     * @var Loop The loop.
     */
    protected $loop;

    /**
     * Sets the PDO.
     *
     * The PDO object MUST be configured with PDO::ATTR_ERRMODE
     * to throw exceptions on errors.
     *
     * As this implementation spans a transaction over a unit of work,
     * PDO::ATTR_AUTOCOMMIT SHOULD not be enabled.
     *
     * @param PDOConnection $pdo     The PDO.
     * @param int  $timeout The timeout in seconds, default is 3.
     *
     * @throws \LengthException The timeout must be greater than 0.
     */
    public function __construct(PDOConnection $connection, int $timeout = 3)
    {
        $pdo = $connection->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('The pdo must have PDO::ERRMODE_EXCEPTION set.');
        }
        self::checkAutocommit($pdo);

        $this->pdo = $pdo;
        $this->loop = new Loop($timeout);
    }

    /**
     * Checks that the AUTOCOMMIT mode is turned off.
     *
     * @param \PDO $pdo PDO
     */
    private static function checkAutocommit(\PDO $pdo): void
    {
        $vendor = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // MySQL turns autocommit off during a transaction.
        if ($vendor == 'mysql') {
            return;
        }

        try {
            if ($pdo->getAttribute(\PDO::ATTR_AUTOCOMMIT)) {
                throw new InvalidArgumentException('PDO::ATTR_AUTOCOMMIT should be disabled.');
            }
        } catch (PDOException $e) {
            /*
             * Ignore this, as some drivers would throw an exception for an
             * unsupported attribute (e.g. Postgres).
             */
        }
    }
}