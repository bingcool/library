<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

/**
 * 国内这几家 SMTP 的固定主机。账号仍由配置填入，这里只约束端口、加密和发件人。
 */
final class KnownProviders
{
    public const DINGTALK = 'smtp.em.dingtalk.com';

    public const QQ = 'smtp.qq.com';

    public const EXMAIL = 'smtp.exmail.qq.com';

    public const NETEASE_163 = 'smtp.163.com';

    public const NETEASE_126 = 'smtp.126.com';

    public const NETEASE_YEAH = 'smtp.yeah.net';

    public const NETEASE_QIYE = 'smtphz.qiye.163.com';

    public const NETEASE_QIYE_LEGACY = 'smtp.qiye.163.com';

    public const FEISHU = 'smtp.feishu.cn';

    /**
     * 这些主机拒绝「发件人地址 ≠ 登录账号」。
     *
     * @var list<string>
     */
    private const MATCHING_FROM = [
        self::DINGTALK,
        self::QQ,
        self::EXMAIL,
        self::NETEASE_163,
        self::NETEASE_126,
        self::NETEASE_YEAH,
        self::NETEASE_QIYE,
        self::NETEASE_QIYE_LEGACY,
        self::FEISHU,
    ];

    public static function requiresMatchingFrom(string $host): bool
    {
        return in_array(strtolower($host), self::MATCHING_FROM, true);
    }

    public static function isDingtalk(string $host): bool
    {
        return strtolower($host) === self::DINGTALK;
    }
}
