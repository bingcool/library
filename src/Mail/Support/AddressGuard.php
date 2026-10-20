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
    /**
     * 校验邮箱格式和显示名。CR/LF 必须先拒绝，否则显示名会变成额外的邮件头。
     */
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

    /**
     * 供主题、附件名、embed cid 等直接进入邮件头的字段使用，只检查换行注入。
     *
     * @param string $field 错误信息里的字段名
     */
    public static function assertHeader(string $value, string $field): void
    {
        if (self::hasLineBreak($value)) {
            throw new InvalidMailMessageException(sprintf('Mail %s must not contain line breaks', $field));
        }
    }

    /**
     * MIME 只允许裸 type/subtype（RFC token 字符），拒绝参数、分号、CR/LF 和控制字符。
     * 空字符串表示交给 PHPMailer 推断，不构成注入面。
     */
    public static function assertMimeType(string $type, string $field = 'attachment mime type'): void
    {
        if ($type === '') {
            return;
        }

        if (strlen($type) > 127 || preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/', $type) !== 1) {
            throw new InvalidMailMessageException(sprintf('Mail %s is invalid', $field));
        }
    }

    /**
     * LF 和 CR 都算换行，单独一个也不能放过。
     */
    private static function hasLineBreak(string $value): bool
    {
        return str_contains($value, "\r") || str_contains($value, "\n");
    }
}
