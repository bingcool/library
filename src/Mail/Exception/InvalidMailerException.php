<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Exception;

/**
 * 邮件账号配置错误：未知 transport、缺主机或发件人、加密和端口不匹配、授权码缺失。
 * 这是配置问题，Job 不要重试。
 */
final class InvalidMailerException extends MailException
{
}
