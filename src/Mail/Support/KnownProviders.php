<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

/**
 * 国内这几家 SMTP 的固定主机。账号仍由配置填入：
 * 钉钉额外约束端口/加密，所有列出的服务商都要求 from.address 与登录账号一致。
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

    /**
     * 主机是否属于「from.address 必须等于登录账号」的服务商。
     */
    public static function requiresMatchingFrom(string $host): bool
    {
        return in_array(strtolower($host), self::MATCHING_FROM, true);
    }

    /**
     * 钉钉邮箱额外强制 465 + ssl（587 未对所有 IP 开放，25 常被云厂商封禁）。
     */
    public static function isDingtalk(string $host): bool
    {
        return strtolower($host) === self::DINGTALK;
    }
}
