<?php

declare(strict_types=1);

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
use Swoolefy\Library\Db\Pgsql;

/**
 * PostgreSQL advisory lock（malkusch/lock 3.1.x，替代 2.x 的 PgAdvisoryLockMutex）。
 *
 * - acquireTimeout 有限时使用 pg_try_advisory_lock + 自旋重试（usleep 在协程内让出）；
 * - 上游 INF 会走 pg_advisory_lock 无限阻塞，本封装拒绝 INF，需要无限等待时直接用上游类；
 * - synchronized() 使用 CoroutineSynchronizedTrait：调用方协程获取锁，回调在 goApp()
 *   子协程执行，子协程 defer 用同一个 PDO 连接释放 advisory lock 后父协程才返回。
 *
 * 协程前提：Worker 已启用 SWOOLE_HOOK_PDO_PGSQL，否则等待会阻塞当前 Worker。
 */
final class PostgreSQLMutex extends \Malkusch\Lock\Mutex\PostgreSQLMutex
{
    use CoroutineSynchronizedTrait;

    /**
     * @param Pgsql $pgsql 已连接的 PostgreSQL 连接对象
     * @param string $name 锁名，上游会哈希为两个 32 位 advisory lock key
     * @param float $acquireTimeout 获取锁的最大等待秒数，必须有限且 > 0
     *
     * @throws InvalidArgumentException 连接未建立、锁名为空或超时非法
     */
    public function __construct(Pgsql $pgsql, string $name, float $acquireTimeout = 5.0)
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('The PostgreSQL lock name must not be empty');
        }

        if (!is_finite($acquireTimeout) || $acquireTimeout <= 0) {
            throw new InvalidArgumentException(sprintf(
                'The lock acquire timeout must be finite and greater than 0 (%s given)',
                self::formatTimeout($acquireTimeout)
            ));
        }

        $pdo = $pgsql->getPdo();
        if (!$pdo instanceof \PDO) {
            throw new InvalidArgumentException('The PostgreSQL connection is not established: getPdo() returned null');
        }

        parent::__construct($pdo, $name, $acquireTimeout);
    }

    private static function formatTimeout(float $timeout): string
    {
        return is_nan($timeout) ? 'NAN' : (is_finite($timeout) ? (string) $timeout : 'INF');
    }
}
