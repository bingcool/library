<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Exception;

/**
 * 触发发送限流窗口上限，或命中同号码重发间隔。
 *
 * 验证码服务 `SmsVerifier` 复用库内 `RateLimit` + `Redis` 做前置流控，命中即抛本异常。
 */
final class RateLimitExceededException extends SmsException
{
}
