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
use Predis\ClientInterface as PredisClientInterface;
use Swoolefy\Library\Redis\Predis as SwoolefyPredis;
use Swoolefy\Library\Redis\Redis as SwoolefyRedis;

/**
 * Redis 分布式锁（malkusch/lock 3.1.x）。
 *
 * 3.1 起上游将 phpredis / RedisCluster / Predis 统一到单客户端实现：锁值使用随机 token，
 * 释放时用 LUA 校验 token，租期通过 PX 毫秒精度写入。
 *
 * synchronized() 使用 CoroutineSynchronizedTrait：调用方协程获取锁，回调在 goApp()
 * 子协程执行，子协程 defer 释放锁后父协程才返回。
 *
 * 协程约束：
 * - 实例持有协议客户端，phpredis 连接在 Swoole 下与协程绑定，**禁止在两个业务协程间共享实例或其连接**；
 * - synchronized() 内部父子协程按「父协程挂起等待、子协程独占使用」的顺序交接连接，属于受控交接；
 * - 每个协程使用自己从组件容器/连接池租借的客户端构造锁。
 */
final class RedisMutex extends \Malkusch\Lock\Mutex\RedisMutex
{
    use CoroutineSynchronizedTrait;

    /**
     * @param object $client 原生 \Redis|\RedisCluster|Predis\ClientInterface，
     *                       或 swoolefy 的 Redis/Predis 包装器（自动解包）
     * @param string $name 锁名，实际 Redis key 为 php-malkusch-lock:{name}
     * @param float $acquireTimeout 获取锁的最大等待秒数，必须 > 0；INF 表示一直等待
     * @param float $leaseTimeout 锁租期秒数，必须有限且 > 0；临界区超过租期会在释放时抛 ExecutionOutsideLockException
     *
     * @throws InvalidArgumentException 客户端类型、锁名或超时参数非法
     */
    public function __construct(
        object $client,
        string $name,
        float $acquireTimeout = 3.0,
        float $leaseTimeout = 30.0
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('The Redis lock name must not be empty');
        }

        if (!($acquireTimeout > 0)) {
            throw new InvalidArgumentException(sprintf(
                'The lock acquire timeout must be greater than 0 (%s given)',
                self::formatTimeout($acquireTimeout)
            ));
        }

        if (!is_finite($leaseTimeout) || $leaseTimeout <= 0) {
            throw new InvalidArgumentException(sprintf(
                'The lock lease timeout must be finite and greater than 0 (%s given)',
                self::formatTimeout($leaseTimeout)
            ));
        }

        parent::__construct(self::unwrapClient($client), $name, $acquireTimeout, $leaseTimeout);
    }

    /**
     * swoolefy 的 Redis/Predis 组件返回的是包装器；3.x 只按原生客户端判断驱动分支，
     * 不解包会把 phpredis 误判为 Predis 并错用 set() 参数形式。
     */
    private static function unwrapClient(object $client): object
    {
        if ($client instanceof SwoolefyRedis) {
            $client = $client->getRedisInstance();
        } elseif ($client instanceof SwoolefyPredis) {
            $client = $client->getPredisInstance();
        }

        if (!$client instanceof \Redis
            && !$client instanceof \RedisCluster
            && !$client instanceof PredisClientInterface
        ) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Redis client %s, expected \\Redis, \\RedisCluster, %s or swoolefy Redis wrapper',
                get_debug_type($client),
                PredisClientInterface::class
            ));
        }

        return $client;
    }

    private static function formatTimeout(float $timeout): string
    {
        return is_nan($timeout) ? 'NAN' : (is_finite($timeout) ? (string) $timeout : 'INF');
    }
}
