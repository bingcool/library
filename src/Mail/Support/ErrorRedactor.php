<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

/**
 * 从 PHPMailer 的 ErrorInfo 里去掉账号、密码和 DKIM 口令。
 * 调用方负责过滤过短的账号；口令再短也必须替换。
 */
final class ErrorRedactor
{
    /**
     * 用 *** 替换所有非空敏感值。
     * 先按长度降序排列，避免短密钥先替换后把长密钥切碎、留下残余片段。
     *
     * @param list<string> $secrets 调用方负责决定哪些账号需要参与替换
     */
    public function clean(string $message, array $secrets): string
    {
        $secrets = array_values(array_unique(array_filter(
            $secrets,
            static fn (string $secret): bool => $secret !== ''
        )));
        // 长的先替换，避免某个密钥是另一个密钥的子串时留下残余
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($secrets as $secret) {
            $message = str_replace($secret, '***', $message);
        }

        return $message;
    }
}
