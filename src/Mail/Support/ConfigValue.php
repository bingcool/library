<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

use Swoolefy\Library\Mail\Exception\InvalidMailerException;

/**
 * 严格解析配置里的布尔和整数，避免 'false'、'20MB'、'10s' 被静默转换。
 */
final class ConfigValue
{
    /**
     * 严格布尔解析：接受 bool、0/1，以及 filter_var 可识别的字符串
     * （'true'/'false'/'1'/'0'/'yes'/'no'/'on'/'off' 等），其余一律拒绝。
     * 不直接用 (bool)，避免 'false' 被当成 true。
     */
    public static function boolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value) && trim($value) !== '') {
            $parsed = filter_var(trim($value), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new InvalidMailerException(sprintf('Mail config [%s] must be a boolean, got %s', $field, self::describe($value)));
    }

    /**
     * 严格整数解析：只接受 int 或纯整数字符串（可带负号）。
     * '20MB'、'10s'、'465abc'、'0.5' 不会被截断成数字，而是抛配置异常。
     */
    public static function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match('/^-?[0-9]+$/', $trimmed) === 1) {
                $parsed = filter_var($trimmed, FILTER_VALIDATE_INT);
                if ($parsed !== false) {
                    return $parsed;
                }
            }
        }

        throw new InvalidMailerException(sprintf('Mail config [%s] must be an integer, got %s', $field, self::describe($value)));
    }

    /**
     * 错误信息里保留原始值和类型，方便定位配置写错的位置。
     */
    private static function describe(mixed $value): string
    {
        if (is_scalar($value)) {
            return var_export($value, true);
        }

        return get_debug_type($value);
    }
}
