<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Exception;

/**
 * SMTP 已接受至少一个收件人的邮件正文，但另有收件人被拒。
 * Job 不要整封重试，否则已成功的收件人会收到重复邮件。
 */
final class PartialDeliveryException extends MailException
{
    /**
     * @param string $message 已脱敏的传输错误
     * @param int $acceptedRecipients DATA 被接受后触发成功回调的收件人数
     */
    public function __construct(string $message, public readonly int $acceptedRecipients)
    {
        parent::__construct($message);
    }
}
