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
     * @param array<string, mixed> $config 归一化后的 SMTP 配置，端口与加密方式已分别校验
     */
    public function __construct(private array $config)
    {
    }

    /**
     * 写入 SMTP 连接参数。关键点：
     * - ssl 对应隐式 TLS（SMTPS），tls 对应 STARTTLS，空字符串不加密；
     * - SMTPAutoTLS 未显式配置时只在 tls 下开启，避免明文端口被自动升级；
     * - SMTPDebug=0 防止 AUTH 对话进入日志，SMTPKeepAlive=false 不跨发送复用连接。
     */
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
        $mailer->SMTPAutoTLS = (bool) ($this->config['autotls'] ?? $encryption === 'tls');
    }

    /**
     * @return string 固定为 smtp；PhpMailerMailer 借此启用部分投递观测
     */
    public function name(): string
    {
        return 'smtp';
    }
}
