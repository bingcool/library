<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Support;

use Swoolefy\Library\Sms\Exception\InvalidProviderException;

/**
 * 模板别名 → 厂商模板码。
 *
 * 已配置的别名走映射。看起来像厂商真实码（SMS_100001、纯数字）原样放行。
 * 未知别名一律抛错（包括未配置任何 templates 的情况），避免把 login_code 这种名字直接发给厂商。
 */
final class TemplateCodeResolver
{
    /**
     * @param array<string, mixed> $templates
     */
    public static function resolve(array $templates, string $alias): string
    {
        if (array_key_exists($alias, $templates) && $templates[$alias] !== null && (string) $templates[$alias] !== '') {
            return (string) $templates[$alias];
        }

        if (self::isRawCode($alias)) {
            return $alias;
        }

        if ($templates === []) {
            throw new InvalidProviderException(
                "Sms template alias [{$alias}] cannot be sent: no templates mapping is configured"
            );
        }

        throw new InvalidProviderException("Sms template alias [{$alias}] is not configured");
    }

    public static function isRawCode(string $alias): bool
    {
        return (bool) preg_match('/^(SMS_[A-Za-z0-9_]+|\d{3,})$/', $alias);
    }
}
