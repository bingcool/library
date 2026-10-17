<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Transport;

use Swoolefy\Library\Mail\Contracts\MailerInterface;
use Swoolefy\Library\Mail\MailMessage;
use Swoolefy\Library\Mail\Support\EmailMask;
use Swoolefy\Library\Mail\Support\MessageCompiler;
use Swoolefy\Library\Mail\ValueObject\MailResult;

/**
 * 不创建 PHPMailer，也不连 SMTP。校验和模板与正式发送相同，信封留在内存里给联调和单测。
 */
final class LogTransport implements MailerInterface
{
    private const MAX_SENT = 32;

    /** @var list<array{subject: string, from: string, to: list<string>, cc: list<string>, bcc_count: int, attachments: int, embeds: int}> */
    private array $sent = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
        private MessageCompiler $compiler,
    ) {
    }

    public function send(MailMessage $message): MailResult
    {
        $prepared = $this->compiler->compile($message, $this->config);
        $to = [];
        foreach ($prepared->to as $address) {
            $to[] = EmailMask::mask($address->email);
        }
        $cc = [];
        foreach ($prepared->cc as $address) {
            $cc[] = EmailMask::mask($address->email);
        }

        $this->sent[] = [
            'subject' => $prepared->subject,
            'from' => EmailMask::mask($prepared->from->email),
            'to' => $to,
            'cc' => $cc,
            'bcc_count' => count($prepared->bcc),
            'attachments' => count($prepared->attachments),
            'embeds' => count($prepared->embeds),
        ];
        if (count($this->sent) > self::MAX_SENT) {
            $this->sent = array_slice($this->sent, -self::MAX_SENT);
        }

        return new MailResult('log-' . bin2hex(random_bytes(8)), $this->transport());
    }

    public function transport(): string
    {
        return 'log';
    }

    /**
     * @return list<array{subject: string, from: string, to: list<string>, cc: list<string>, bcc_count: int, attachments: int, embeds: int}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
