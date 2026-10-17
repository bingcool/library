<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Support;

/**
 * 厂商 SDK 连接 / 读取超时。配置项 timeout 单位是秒，缺省 5 秒，避免远端无响应时协程一直挂住。
 */
final class ClientTimeout
{
    public const DEFAULT_SECONDS = 5;

    /**
     * @param array<string, mixed> $config
     */
    public static function seconds(array $config): int
    {
        $timeout = (int) ($config['timeout'] ?? self::DEFAULT_SECONDS);

        return $timeout > 0 ? $timeout : self::DEFAULT_SECONDS;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function milliseconds(array $config): int
    {
        return self::seconds($config) * 1000;
    }
}
