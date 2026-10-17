<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Transport;

use PHPMailer\PHPMailer\PHPMailer;
use Swoolefy\Library\Mail\Contracts\TransportInterface;

/**
 * 本机 sendmail。isSendmail() 会按 php.ini 改写路径，所以必须在它之后再写回配置的可执行文件。
 * PHPMailer 自己追加 -i -t，并用 escapeshellcmd 包住整段路径，因此 path 不能带参数。
 */
final class SendmailTransport implements TransportInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config)
    {
    }

    public function apply(PHPMailer $mailer): void
    {
        $mailer->isSendmail();
        $mailer->Sendmail = (string) $this->config['sendmail_path'];
    }

    public function name(): string
    {
        return 'sendmail';
    }
}
