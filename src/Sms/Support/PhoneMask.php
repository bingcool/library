<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Support;

/**
 * 日志 / 异常信息里的号码脱敏工具。
 *
 * 安全底线：`access_secret` / `secret_key` 与验证码明文一律不进日志；号码必须脱敏后再落。
 */
final class PhoneMask
{
    /**
     * 单号码脱敏。
     *
     * 规则：
     * - 常见 11 位手机号：保留前 3 后 4 → `138****8000`
     * - 其它长度：保留前 2 后 2，中间以 `****` 占位；过短（<=4）全遮为 `****`
     *
     * @param string $phone 已规范化（未含分隔符）的单个号码
     */
    public static function mask(string $phone): string
    {
        $phone = trim($phone);
        $len = strlen($phone);

        if ($len <= 4) {
            return '****';
        }

        if ($len === 11) {
            return substr($phone, 0, 3) . '****' . substr($phone, -4);
        }

        return substr($phone, 0, 2) . '****' . substr($phone, -2);
    }

    /**
     * 批量脱敏，便于把 PhoneList 一次性转成日志安全的形式。
     *
     * @param iterable<string> $phones
     * @return list<string>
     */
    public static function maskAll(iterable $phones): array
    {
        $out = [];
        foreach ($phones as $phone) {
            $out[] = self::mask((string) $phone);
        }

        return $out;
    }
}
