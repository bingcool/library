<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Exception;

/**
 * 验证码存储 / 限流基础设施异常（Redis 连接、Lua、限流器内部错误等）。
 *
 * 统一包装为 SmsException 子类，业务 `catch (SmsException)` 即可覆盖。
 */
final class SmsStorageException extends SmsException
{
}
