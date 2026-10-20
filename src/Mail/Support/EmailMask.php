<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

/**
 * 日志用的邮箱打码：保留首字符和域名，例如 z***@example.com。
 */
final class EmailMask
{
    /**
     * 保留本地部分首字符和完整域名，例如 zhangsan@example.com → z***@example.com。
     * 没有 @ 或 @ 在开头时整体打码。
     */
    public static function mask(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $prefix = function_exists('mb_substr') ? mb_substr($local, 0, 1) : substr($local, 0, 1);

        return $prefix . '***@' . $domain;
    }

    /**
     * 批量打码，保持原顺序。
     *
     * @param list<string> $emails
     * @return list<string>
     */
    public static function maskAll(array $emails): array
    {
        $out = [];
        foreach ($emails as $email) {
            $out[] = self::mask($email);
        }

        return $out;
    }
}
