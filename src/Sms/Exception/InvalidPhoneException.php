<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Exception;

/**
 * 手机号非法（格式不符 / 为空）或超出单次发送号码上限。
 */
final class InvalidPhoneException extends SmsException
{
}
