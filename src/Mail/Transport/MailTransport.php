<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Transport;

use PHPMailer\PHPMailer\PHPMailer;
use Swoolefy\Library\Mail\Contracts\TransportInterface;
use Swoolefy\Library\Mail\Support\BlockingTransportWarning;

/**
 * PHP mail()。和 sendmail 传输不是一回事：这里通常只知道成功或失败，没有 SMTP 拒信原因。
 * mail() 不受 Swoole stream hook 覆盖，协程里会阻塞 Worker。
 */
final class MailTransport implements TransportInterface
{
    /**
     * @param array<string, mixed> $config 归一化后的配置；mail() 只需要 from，不使用 host/port
     */
    public function __construct(private array $config)
    {
    }

    /**
     * 切换到 PHP mail()。没有任何连接或认证配置可写。
     * mail() 是 C 层调用，Swoole 的 stream hook 不覆盖，必须在协程内提示阻塞风险。
     */
    public function apply(PHPMailer $mailer): void
    {
        BlockingTransportWarning::warnIfCoroutine('mail');
        $mailer->isMail();
    }

    /**
     * @return string 固定为 mail
     */
    public function name(): string
    {
        return 'mail';
    }
}
