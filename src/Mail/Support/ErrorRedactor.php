<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

/**
 * 从 PHPMailer 的 ErrorInfo 里去掉账号、密码和 DKIM 口令。
 * 短于 4 个字符的密钥不替换，避免把普通英文单词抠掉。
 */
final class ErrorRedactor
{
    /**
     * @param list<string> $secrets
     */
    public function clean(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (strlen($secret) < 4) {
                continue;
            }
            $message = str_replace($secret, '***', $message);
        }

        return $message;
    }
}
