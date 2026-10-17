<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\ValueObject;

/**
 * 发送成功的结果。失败走异常，这里没有 ok=false 这条路。
 * 不含密码、正文和收件人。
 */
final class MailResult
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $transport,
    ) {
    }
}
