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
 * 生产误配 transport=log 时会在首次使用时告警；sent() 不保留明文主题。
 */
final class LogTransport implements MailerInterface
{
    private const MAX_SENT = 32;

    private static bool $warned = false;

    /** @var list<array{subject: string, subject_length: int, from: string, to: list<string>, cc: list<string>, bcc_count: int, attachments: int, embeds: int}> 最近 32 封脱敏信封 */
    private array $sent = [];

    /**
     * @param array<string, mixed> $config 归一化后的 mailer 配置
     * @param MessageCompiler $compiler 与正式发送共用同一套校验和模板渲染
     */
    public function __construct(
        private array $config,
        private MessageCompiler $compiler,
    ) {
    }

    /**
     * 走完整编译流程但不创建 PHPMailer、不建立任何外部连接。
     * 首次使用会写一次 error_log，提示生产误配 transport=log 时邮件并未真正投递。
     * 内存中只保留脱敏信封，最多 32 封，供联调和单测断言。
     */
    public function send(MailMessage $message): MailResult
    {
        $prepared = $this->compiler->compile($message, $this->config);
        $this->warnNotDelivered();

        $to = [];
        foreach ($prepared->to as $address) {
            $to[] = EmailMask::mask($address->email);
        }
        $cc = [];
        foreach ($prepared->cc as $address) {
            $cc[] = EmailMask::mask($address->email);
        }

        $this->sent[] = [
            'subject' => self::maskSubject($prepared->subject),
            'subject_length' => self::subjectLength($prepared->subject),
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

    /**
     * @return string 固定为 log
     */
    public function transport(): string
    {
        return 'log';
    }

    /**
     * 最近发送的脱敏信封，按时间顺序；主题只保留前 2 个字符和长度。
     *
     * @return list<array{subject: string, subject_length: int, from: string, to: list<string>, cc: list<string>, bcc_count: int, attachments: int, embeds: int}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * 每个进程只告警一次，避免联调/单测每封信都刷 error_log。
     */
    private function warnNotDelivered(): void
    {
        if (self::$warned) {
            return;
        }

        self::$warned = true;
        error_log('[swoolefy/mail] transport [log] does not deliver mail; use it only for local debugging and tests');
    }

    /**
     * 主题可能含验证码、订单号等敏感信息，sent() 不保留明文。
     */
    private static function maskSubject(string $subject): string
    {
        $prefix = function_exists('mb_substr') ? mb_substr($subject, 0, 2, 'UTF-8') : substr($subject, 0, 2);

        return $prefix . '***';
    }

    /**
     * 按字符数而非字节数记录长度，保证中文主题的长度符合直觉。
     */
    private static function subjectLength(string $subject): int
    {
        return function_exists('mb_strlen') ? mb_strlen($subject, 'UTF-8') : strlen($subject);
    }
}
