<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail;

/**
 * 邮箱地址和显示名。构造时即完成 trim 与校验，非法地址进不了后续流程。
 * 不可变值对象，供 MailMessage、PreparedMessage 和配置中的 from/reply_to 共用。
 */
final class Address
{
    public readonly string $email;

    public readonly string $name;

    /**
     * @param string $email 邮箱地址；首尾空白会在校验前去掉，避免与服务商账号匹配不一致
     * @param string $name 显示名；含 CR/LF 会被拒绝，防止变成额外的邮件头
     * @throws \Swoolefy\Library\Mail\Exception\InvalidMailMessageException 地址为空、格式非法或含换行
     */
    public function __construct(string $email, string $name = '')
    {
        $email = trim($email);
        Support\AddressGuard::assert($email, $name);
        $this->email = $email;
        $this->name = $name;
    }
}
