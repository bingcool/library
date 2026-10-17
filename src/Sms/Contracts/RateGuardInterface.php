<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Contracts;

/**
 * 限流守卫的窄接口：封装「窗口内是否已超限并记一次消费」。
 *
 * 生产实现 `DurationRateGuard` 复用库内 `RateLimit\DurationLimiter`（滑动窗口 + Lua 原子）；
 * 测试实现 `Testing\ArrayRateGuard` 可脚本化命中/放行。
 */
interface RateGuardInterface
{
    /**
     * 判断 key 在 window 秒内是否已超过 max 次；未超过则记一次消费。
     *
     * @return bool true 表示已超限（应拒绝），false 表示放行
     */
    public function tooMany(string $key, int $max, int $window): bool;
}
