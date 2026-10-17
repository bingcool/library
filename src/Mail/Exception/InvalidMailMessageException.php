<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Exception;

/**
 * 这封信本身不合法：没有收件人、地址或主题非法、邮件头注入、附件超限或读不到。
 * Job 不要重试。
 */
final class InvalidMailMessageException extends MailException
{
}
