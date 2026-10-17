<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

use PHPMailer\PHPMailer\PHPMailer;
use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;

/**
 * 地址和显示名校验。拒绝换行，避免把收件人显示名变成额外的邮件头。
 */
final class AddressGuard
{
    public static function assert(string $email, string $name = ''): void
    {
        if (self::hasLineBreak($email) || self::hasLineBreak($name)) {
            throw new InvalidMailMessageException('Mail address or display name must not contain line breaks');
        }

        $email = trim($email);
        if ($email === '' || !PHPMailer::validateAddress($email)) {
            throw new InvalidMailMessageException('Invalid mail address');
        }
    }

    public static function assertHeader(string $value, string $field): void
    {
        if (self::hasLineBreak($value)) {
            throw new InvalidMailMessageException(sprintf('Mail %s must not contain line breaks', $field));
        }
    }

    private static function hasLineBreak(string $value): bool
    {
        return str_contains($value, "\r") || str_contains($value, "\n");
    }
}
