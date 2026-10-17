<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Transport;

use PHPMailer\PHPMailer\PHPMailer;
use Swoolefy\Library\Mail\Contracts\TransportInterface;

/**
 * PHP mail()。和 sendmail 传输不是一回事：这里通常只知道成功或失败，没有 SMTP 拒信原因。
 */
final class MailTransport implements TransportInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config)
    {
    }

    public function apply(PHPMailer $mailer): void
    {
        $mailer->isMail();
    }

    public function name(): string
    {
        return 'mail';
    }
}
