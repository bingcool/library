<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Support;

use Swoolefy\Library\FileStorageSystem\Exception\InvalidExpirationException;

/**
 * 将业务侧的「过期时刻」字符串转为 Unix 时间或相对秒数（预签名 URL 用）。
 */
final class ExpirationParser
{
    /**
     * 解析绝对失效时刻（Y-m-d H:i:s 或可被 strtotime 识别的字符串）为 Unix 秒。
     *
     * @param string $expiration 未来某一时刻
     * @return int Unix 时间戳
     */
    public static function toUnixTimestamp(string $expiration): int
    {
        $expiration = trim($expiration);
        if ($expiration === '') {
            throw new InvalidExpirationException('Empty expiration');
        }

        $ts = strtotime($expiration);
        if ($ts === false) {
            throw new InvalidExpirationException("Invalid expiration: {$expiration}");
        }
        if ($ts <= time()) {
            throw new InvalidExpirationException("Expiration must be in the future: {$expiration}");
        }

        return $ts;
    }

    /**
     * 绝对时刻相对当前的秒数（供 signObjectUrl 的 timeout 参数）。
     *
     * @param string $expiration 未来某一时刻
     * @return int 相对秒数，至少为 1 由调用方保证时可再 max
     */
    public static function toRelativeSeconds(string $expiration): int
    {
        return self::toUnixTimestamp($expiration) - time();
    }
}
