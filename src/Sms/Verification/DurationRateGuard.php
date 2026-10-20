<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Verification;

use Swoolefy\Library\RateLimit\DurationLimiter;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Library\Sms\Contracts\RateGuardInterface;
use Swoolefy\Library\Sms\Exception\InvalidConfigException;
use Swoolefy\Library\Sms\Exception\SmsException;
use Swoolefy\Library\Sms\Exception\SmsStorageException;
use Swoolefy\Library\Sms\Support\RedisComponent;

/**
 * 基于库内 `RateLimit\DurationLimiter`（滑动窗口 + Lua 原子）的限流守卫。
 *
 * 语义对齐：`tooMany()` == `DurationLimiter::isLimit()`（true 表示已超限）。
 * DurationLimiter 在「未超限」时即写入一次消费，符合「发送即占用配额」的验证码防刷需求。
 *
 * 不新造限流机制，仅做接口适配。
 *
 * @see DurationLimiter
 */
final class DurationRateGuard implements RateGuardInterface
{
    /**
     * @param RedisConnection|string $redis 连接，或 cache.php 组件名（sms.php 的 redis_component）
     * @param string $prefix 可选的业务命名空间；默认不加，验证码限流键本身已带 `sms:` 命名空间
     */
    public function __construct(private RedisConnection|string $redis, private string $prefix = '')
    {
    }

    public function tooMany(string $key, int $max, int $window): bool
    {
        if ($max < 1 || $window < 1) {
            throw new InvalidConfigException(sprintf(
                'Rate guard requires max >= 1 and window >= 1, got max=%d window=%d',
                $max,
                $window
            ));
        }

        try {
            return $this->limiter($key, $max, $window)->isLimit();
        } catch (SmsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 库级 RateLimitException / Redis 异常统一归入 Sms 异常边界
            throw new SmsStorageException('Sms rate limiter failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function remaining(string $key, int $window): int
    {
        if ($window < 1) {
            throw new InvalidConfigException(sprintf(
                'Rate guard requires window >= 1, got window=%d',
                $window
            ));
        }

        try {
            return $this->limiter($key, 1, $window)->getRemainingTime();
        } catch (SmsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SmsStorageException('Sms rate limiter failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function limiter(string $key, int $max, int $window): DurationLimiter
    {
        $redis = $this->redis instanceof RedisConnection
            ? $this->redis
            : RedisComponent::connection($this->redis);

        // 每次新建：DurationLimiter 把 key/窗口放在实例属性上，协程共用一个实例会串号
        $limiter = new DurationLimiter($redis);
        $limiter->setRateKey($this->withPrefix($key));
        $limiter->setLimitParams($max, $window);

        return $limiter;
    }

    private function withPrefix(string $key): string
    {
        if ($this->prefix === '' || str_starts_with($key, $this->prefix)) {
            return $key;
        }

        return $this->prefix . $key;
    }
}
