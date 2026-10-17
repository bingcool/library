<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Verification;

use Swoolefy\Library\RateLimit\DurationLimiter;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Library\Sms\Contracts\RateGuardInterface;

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
    public function __construct(private RedisConnection $redis, private string $prefix = 'sms:rg:')
    {
    }

    public function tooMany(string $key, int $max, int $window): bool
    {
        // 每次新建：DurationLimiter 把 key/窗口放在实例属性上，协程共用一个实例会串号
        $limiter = new DurationLimiter($this->redis);
        $limiter->setRateKey($this->strip($key));
        $limiter->setLimitParams($max, $window);

        return $limiter->isLimit();
    }

    private function strip(string $key): string
    {
        if ($this->prefix !== '' && str_starts_with($key, $this->prefix)) {
            return substr($key, strlen($this->prefix));
        }

        return $key;
    }
}
