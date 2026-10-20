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
     * 校验、编译并同步投递一封信。
     * 实现必须每次新建 PHPMailer/连接，不能把当前信件存到实例上跨协程复用。
     *
     * @throws MailException 配置、信件、模板或传输失败；调用方按子类决定是否重试
     */
    public function send(MailMessage $message): MailResult;

    /**
     * 传输名：smtp / sendmail / mail / log，或 extend() 注册的名字。
     * Manager 用它判断是否启用部分投递观测。
     */
    public function transport(): string;
}
