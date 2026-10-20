<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\ValueObject;

/**
 * 发送成功的结果。失败走异常，这里没有 ok=false 这条路。
 * 不含密码、正文和收件人。
 */
final class MailResult
{
    /**
     * @param string $messageId PHPMailer 生成的 Message-ID；log 传输为 log-{hex}
     * @param string $transport 实际使用的传输名
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $transport,
    ) {
    }
}
