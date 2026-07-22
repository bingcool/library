<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth\Support;

/**
 * 配置字符串 openid_mode → Yurun OpenidMode 整型常量。
 *
 * QQ / 微信 SDK 用 int 区分 openid 取值字段：
 * - 1 OPEN_ID：只用 openid
 * - 2 UNION_ID：只用 unionid（无则可能空）
 * - 3 UNION_ID_FIRST：优先 unionid，否则 openid
 *
 * 配置侧用可读字符串，避免业务硬编码魔法数字。
 */
final class OpenidModeMapper
{
    /** 与 Yurun\OAuthLogin\QQ\OpenidMode::OPEN_ID / Weixin 同值 */
    public const OPEN_ID = 1;

    public const UNION_ID = 2;

    public const UNION_ID_FIRST = 3;

    /**
     * 将配置值规范为 SDK 可识别的 int。
     *
     * 非法或空值一律回落 OPEN_ID，避免因拼写错误导致 SDK 行为未定义。
     *
     * @param string|int|null $mode openid | unionid | unionid_first | 1|2|3
     */
    public static function toInt(string|int|null $mode): int
    {
        if ($mode === null || $mode === '') {
            return self::OPEN_ID;
        }

        // 已是 int：白名单校验，防止传入任意整数
        if (is_int($mode)) {
            return match ($mode) {
                self::OPEN_ID, self::UNION_ID, self::UNION_ID_FIRST => $mode,
                default => self::OPEN_ID,
            };
        }

        // 字符串：兼容 open_id / union_id 下划线写法
        return match (strtolower(trim($mode))) {
            'openid', 'open_id' => self::OPEN_ID,
            'unionid', 'union_id' => self::UNION_ID,
            'unionid_first', 'union_id_first' => self::UNION_ID_FIRST,
            default => self::OPEN_ID,
        };
    }
}
