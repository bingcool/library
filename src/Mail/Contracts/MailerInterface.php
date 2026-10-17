<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Contracts;

use Swoolefy\Library\Mail\Exception\MailException;
use Swoolefy\Library\Mail\MailMessage;
use Swoolefy\Library\Mail\ValueObject\MailResult;

/**
 * 一个已绑定配置的发信器。实现里不得缓存 PHPMailer，也不得保存当前这封信。
 */
interface MailerInterface
{
    /**
     * @throws MailException 配置、信件、模板或传输失败
     */
    public function send(MailMessage $message): MailResult;

    /**
     * 传输名：smtp / sendmail / mail / log，或 extend() 注册的名字。
     */
    public function transport(): string;
}
