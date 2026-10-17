<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Transport;

use PHPMailer\PHPMailer\PHPMailer;
use Swoolefy\Library\Mail\Contracts\TransportInterface;

/**
 * 直连 SMTP。每次 apply 都写进一个新的 PHPMailer，不保留连接。
 */
final class SmtpTransport implements TransportInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config)
    {
    }

    public function apply(PHPMailer $mailer): void
    {
        $encryption = (string) $this->config['encryption'];

        $mailer->isSMTP();
        $mailer->Host = (string) $this->config['host'];
        $mailer->Port = (int) $this->config['port'];
        $mailer->SMTPAuth = (bool) $this->config['auth'];
        $mailer->Username = (string) $this->config['username'];
        $mailer->Password = (string) $this->config['password'];
        $mailer->Timeout = (int) $this->config['timeout'];
        $mailer->SMTPDebug = 0;
        $mailer->SMTPKeepAlive = false;
        $mailer->SMTPSecure = match ($encryption) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        // 明文 25 端口不要被 PHPMailer 自动升成 STARTTLS，否则和配置的「不加密」不一致
        $mailer->SMTPAutoTLS = $encryption === 'tls';
    }

    public function name(): string
    {
        return 'smtp';
    }
}
