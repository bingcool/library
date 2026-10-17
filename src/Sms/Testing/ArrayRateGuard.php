<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Testing;

use Swoolefy\Library\Sms\Contracts\RateGuardInterface;

/**
 * 内存 / 脚本化限流守卫，供单测确定性命中或放行。
 *
 * 两种模式：
 * - 计数模式：按 key 计数，超过 max 判定超限（近似 DurationLimiter 语义：放行时也记一次消费）
 * - 固定模式：`$alwaysLimit` 强制 always 超限 / 放行，便于单点断言
 */
final class ArrayRateGuard implements RateGuardInterface
{
    /** @var array<string, int> */
    private array $counts = [];

    /**
     * @param bool|null $alwaysLimit null=按计数；true=永远超限；false=永远放行
     */
    public function __construct(private ?bool $alwaysLimit = null)
    {
    }

    public function tooMany(string $key, int $max, int $window): bool
    {
        if ($this->alwaysLimit !== null) {
            // 固定模式下仍然累加，便于断言「被调用过」
            $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

            return $this->alwaysLimit;
        }

        $current = ($this->counts[$key] ?? 0);
        if ($current >= $max) {
            return true;
        }
        $this->counts[$key] = $current + 1;

        return false;
    }

    public function count(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }

    public function reset(): void
    {
        $this->counts = [];
    }
}
