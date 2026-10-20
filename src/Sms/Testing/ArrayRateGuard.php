<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Testing;

use Swoolefy\Library\Sms\Contracts\RateGuardInterface;
use Swoolefy\Library\Sms\Exception\InvalidConfigException;

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
     * @param int|null $remainingSeconds 固定剩余窗口秒数；null=返回完整 window
     */
    public function __construct(
        private ?bool $alwaysLimit = null,
        private ?int $remainingSeconds = null,
    ) {
    }

    public function tooMany(string $key, int $max, int $window): bool
    {
        // 与生产 DurationRateGuard 对齐，避免测试放行生产会拒绝的配置
        if ($max < 1 || $window < 1) {
            throw new InvalidConfigException(sprintf(
                'Rate guard requires max >= 1 and window >= 1, got max=%d window=%d',
                $max,
                $window
            ));
        }

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

    public function remaining(string $key, int $window): int
    {
        if ($window < 1) {
            throw new InvalidConfigException(sprintf(
                'Rate guard requires window >= 1, got window=%d',
                $window
            ));
        }

        if ($this->remainingSeconds === null) {
            return $window;
        }

        return max(1, min($this->remainingSeconds, $window));
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
