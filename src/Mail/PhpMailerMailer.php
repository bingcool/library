<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use Swoolefy\Library\Mail\Contracts\MailerInterface;
use Swoolefy\Library\Mail\Contracts\TransportInterface;
use Swoolefy\Library\Mail\Exception\MailTransportException;
use Swoolefy\Library\Mail\Support\ErrorRedactor;
use Swoolefy\Library\Mail\Support\MessageCompiler;
use Swoolefy\Library\Mail\Support\PreparedMessage;
use Swoolefy\Library\Mail\ValueObject\MailResult;

/**
 * smtp / sendmail / mail 共用。每次 send() 新建 PHPMailer，用完即弃。
 */
final class PhpMailerMailer implements MailerInterface
{
    /**
     * @param array<string, mixed> $config
     * @param \Closure(): PHPMailer|null $phpMailerFactory 单测注入替身；生产为 null
     */
    public function __construct(
        private TransportInterface $transport,
        private array $config,
        private MessageCompiler $compiler,
        private ?\Closure $phpMailerFactory = null,
        private ErrorRedactor $redactor = new ErrorRedactor(),
    ) {
    }

    public function send(MailMessage $message): MailResult
    {
        $prepared = $this->compiler->compile($message, $this->config);
        $mailer = $this->phpMailerFactory !== null
            ? ($this->phpMailerFactory)()
            : new PHPMailer(true);

        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->SMTPDebug = 0;
        $mailer->SMTPKeepAlive = false;
        $mailer->Timeout = (int) ($this->config['timeout'] ?? 10);
        $this->transport->apply($mailer);
        $this->applyDkim($mailer);
        $this->fill($mailer, $prepared);

        try {
            $mailer->send();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            // 不把原始异常串进链里，避免 ErrorInfo 里的口令留在 previous 上
            throw new MailTransportException($this->redactor->clean($e->getMessage(), $this->secrets()));
        }

        $messageId = $mailer->getLastMessageID();

        return new MailResult($messageId !== '' ? $messageId : $mailer->MessageID, $this->transport());
    }

    public function transport(): string
    {
        return $this->transport->name();
    }

    private function fill(PHPMailer $mailer, PreparedMessage $message): void
    {
        $mailer->setFrom($message->from->email, $message->from->name);
        if ($message->replyTo !== null) {
            $mailer->addReplyTo($message->replyTo->email, $message->replyTo->name);
        }
        foreach ($message->to as $address) {
            $mailer->addAddress($address->email, $address->name);
        }
        foreach ($message->cc as $address) {
            $mailer->addCC($address->email, $address->name);
        }
        foreach ($message->bcc as $address) {
            $mailer->addBCC($address->email, $address->name);
        }

        $mailer->Subject = $message->subject;
        if ($message->html !== null) {
            $mailer->isHTML(true);
            $mailer->Body = $message->html;
            $mailer->AltBody = $message->text ?? '';
        } else {
            $mailer->isHTML(false);
            $mailer->Body = $message->text ?? '';
        }

        foreach ($message->attachments as $file) {
            if (isset($file['data'])) {
                $mailer->addStringAttachment($file['data'], $file['name'], PHPMailer::ENCODING_BASE64, $file['mime']);
                continue;
            }
            $mailer->addAttachment($file['path'], $file['name'], PHPMailer::ENCODING_BASE64, $file['mime']);
        }
        foreach ($message->embeds as $image) {
            $mailer->addEmbeddedImage($image['path'], $image['cid'], $image['name']);
        }
    }

    private function applyDkim(PHPMailer $mailer): void
    {
        $dkim = $this->config['dkim'] ?? null;
        if (!is_array($dkim)) {
            return;
        }

        $mailer->DKIM_domain = (string) $dkim['domain'];
        $mailer->DKIM_selector = (string) $dkim['selector'];
        $mailer->DKIM_private = (string) $dkim['private'];
        $mailer->DKIM_passphrase = (string) $dkim['passphrase'];
    }

    /**
     * @return list<string>
     */
    private function secrets(): array
    {
        $dkim = is_array($this->config['dkim'] ?? null) ? $this->config['dkim'] : [];

        return [
            (string) ($this->config['password'] ?? ''),
            (string) ($this->config['username'] ?? ''),
            (string) ($dkim['passphrase'] ?? ''),
        ];
    }
}
