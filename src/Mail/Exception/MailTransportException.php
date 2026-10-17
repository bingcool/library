<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Exception;

/**
 * SMTP / sendmail / mail() 在投递阶段失败：连不上、认证失败、超时或对端拒绝。
 * PHPMailer 不区分这几种，统一用本异常。消息里的账号和密码已去掉。Job 可以重试。
 */
final class MailTransportException extends MailException
{
}
