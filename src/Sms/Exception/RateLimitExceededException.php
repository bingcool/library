<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Exception;

/**
 * 触发发送限流窗口上限，或命中同号码重发间隔。
 *
 * 验证码服务 `SmsVerifier` 复用库内 `RateLimit` + `Redis` 做前置流控，命中即抛本异常。
 * 业务可据 `retryAfter()` 设置 HTTP `Retry-After`，`reason()` 区分冷却与窗口限流。
 */
final class RateLimitExceededException extends SmsException
{
    public const REASON_COOLDOWN = 'cooldown';

    public const REASON_WINDOW = 'window';

    /**
     * @param int $retryAfter 建议重试等待秒数（>0）
     */
    public function __construct(
        string $message,
        private string $reason = self::REASON_WINDOW,
        private int $retryAfter = 1,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function retryAfter(): int
    {
        return max(1, $this->retryAfter);
    }
}
