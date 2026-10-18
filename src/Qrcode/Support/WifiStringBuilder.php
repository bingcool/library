<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\Support;

use Swoolefy\Library\Qrcode\Exception\InvalidQrcodeContentException;

/**
 * 构造 WiFi 二维码标准 payload：`WIFI:T:...;S:...;P:...;;`
 * 对 SSID/密码中的 `\ ; , "` 等做转义，避免扫不出来。
 */
final class WifiStringBuilder
{
    /**
     * @param string $auth WPA|WEP|nopass|NONE（NONE 归一为 nopass）
     *
     * @throws InvalidQrcodeContentException SSID 空或 auth 非法
     */
    public static function build(string $ssid, string $password, string $auth = 'WPA'): string
    {
        $ssid = trim($ssid);
        if ($ssid === '') {
            throw new InvalidQrcodeContentException('WiFi SSID is required');
        }

        $auth = strtoupper(trim($auth));
        if (!in_array($auth, ['WPA', 'WEP', 'NOPASS', 'NONE'], true)) {
            throw new InvalidQrcodeContentException('WiFi auth must be WPA, WEP, or nopass');
        }
        if ($auth === 'NONE') {
            $auth = 'nopass';
        }

        return sprintf(
            'WIFI:T:%s;S:%s;P:%s;;',
            self::escape($auth),
            self::escape($ssid),
            self::escape($password),
        );
    }

    private static function escape(string $value): string
    {
        return addcslashes($value, "\\;,\":");
    }
}
