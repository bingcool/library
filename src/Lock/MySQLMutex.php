<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\Lock;

use InvalidArgumentException;
use Swoolefy\Library\Db\Mysql;

/**
 * MySQL GET_LOCK 互斥锁（malkusch/lock 3.1.x）。
 *
 * synchronized() 使用 CoroutineSynchronizedTrait：调用方协程获取锁，回调在 goApp()
 * 子协程执行，子协程 defer 释放锁后父协程才返回。GET_LOCK/RELEASE_LOCK 都走同一个
 * PDO 连接，父子协程按「父协程挂起等待、子协程独占使用」的顺序交接。
 *
 * 注意：Swoole 未提供 PDO_MYSQL 的协程 hook，GET_LOCK 的等待与 PDO 查询会阻塞当前 Worker。
 * 请求路径中应使用较短的 acquireTimeout，或优先选择 RedisMutex。
 */
final class MySQLMutex extends \Malkusch\Lock\Mutex\MySQLMutex
{
    use CoroutineSynchronizedTrait;

    /**
     * @param Mysql $mysql 已连接的 MySQL 连接对象
     * @param string $name 锁名；上游会加 php-malkusch-lock: 前缀，name 最长 46 字符
     * @param float $acquireTimeout 获取锁的最大等待秒数，>= 0；0 表示只尝试一次
     *
     * @throws InvalidArgumentException 连接未建立、锁名为空或超时非法
     */
    public function __construct(Mysql $mysql, string $name, float $acquireTimeout = 3.0)
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('The MySQL lock name must not be empty');
        }

        if (!is_finite($acquireTimeout) || $acquireTimeout < 0) {
            throw new InvalidArgumentException(sprintf(
                'The lock acquire timeout must be finite and greater than or equal to 0 (%s given)',
                self::formatTimeout($acquireTimeout)
            ));
        }

        $pdo = $mysql->getPdo();
        if (!$pdo instanceof \PDO) {
            throw new InvalidArgumentException('The MySQL connection is not established: getPdo() returned null');
        }

        parent::__construct($pdo, $name, $acquireTimeout);
    }

    private static function formatTimeout(float $timeout): string
    {
        return is_nan($timeout) ? 'NAN' : (is_finite($timeout) ? (string) $timeout : 'INF');
    }
}
