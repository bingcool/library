<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Transport;

use PHPMailer\PHPMailer\PHPMailer;
use Swoolefy\Library\Mail\Contracts\TransportInterface;
use Swoolefy\Library\Mail\Support\BlockingTransportWarning;

/**
 * 本机 sendmail。isSendmail() 会按 php.ini 改写路径，所以必须在它之后再写回配置的可执行文件。
 * PHPMailer 自己追加 -i -t，并用 escapeshellcmd 包住整段路径，因此 path 不能带参数。
 * 子进程等待是否能 hook 取决于进程 hook 配置，协程里按阻塞风险告警。
 */
final class SendmailTransport implements TransportInterface
{
    /**
     * @param array<string, mixed> $config 归一化后的配置，sendmail_path 已通过绝对路径和可执行校验
     */
    public function __construct(private array $config)
    {
    }

    /**
     * 切换到 sendmail。isSendmail() 会先按 php.ini 改写 Sendmail，
     * 所以必须在其后再写回配置的可执行文件；-i -t 由 PHPMailer 自行追加。
     */
    public function apply(PHPMailer $mailer): void
    {
        BlockingTransportWarning::warnIfCoroutine('sendmail');
        $mailer->isSendmail();
        $mailer->Sendmail = (string) $this->config['sendmail_path'];
    }

    /**
     * @return string 固定为 sendmail
     */
    public function name(): string
    {
        return 'sendmail';
    }
}
