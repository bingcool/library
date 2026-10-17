<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

use Swoolefy\Library\Mail\Address;
use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;
use Swoolefy\Library\Mail\MailMessage;
use Swoolefy\Library\Mail\Template\TemplateEngineInterface;

/**
 * 模板渲染、纯文本降级、附件限额、发件人与登录账号一致性。log 和 PHPMailer 共用。
 */
final class MessageCompiler
{
    public function __construct(
        private TemplateEngineInterface $templates,
        private array $globalVars,
        private int $maxBytes,
        private AttachmentNormalizer $attachments = new AttachmentNormalizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $mailerConfig MailerConfigValidator 归一化后的配置
     */
    public function compile(MailMessage $message, array $mailerConfig): PreparedMessage
    {
        if ($message->to === []) {
            throw new InvalidMailMessageException('Mail message requires at least one recipient');
        }

        $subject = trim($message->subject);
        if ($subject === '') {
            throw new InvalidMailMessageException('Mail subject is required');
        }
        AddressGuard::assertHeader($subject, 'subject');

        $html = $message->html;
        if ($message->template !== null) {
            $html = $this->templates->render($message->template, $this->vars($message));
        }
        if ($html === '') {
            $html = null;
        }

        $text = $message->text;
        if ($text === '') {
            $text = null;
        }
        if ($text === null && $html !== null) {
            $text = HtmlToText::convert($html);
        }
        if ($html === null && ($text === null || $text === '')) {
            throw new InvalidMailMessageException('Mail message requires html or text');
        }

        /** @var Address $from */
        $from = $message->from ?? $mailerConfig['from'];
        $username = (string) ($mailerConfig['username'] ?? '');
        if (($mailerConfig['match_from'] ?? false) === true && strcasecmp($from->email, $username) !== 0) {
            throw new InvalidMailMessageException('Message from address must match the SMTP username');
        }

        $files = $this->attachments->normalize($message, $this->maxBytes);
        $replyTo = $message->replyTo ?? $mailerConfig['reply_to'];

        return new PreparedMessage(
            $from,
            $replyTo instanceof Address ? $replyTo : null,
            $message->to,
            $message->cc,
            $message->bcc,
            $subject,
            $html,
            $text,
            $files['attachments'],
            $files['embeds'],
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function vars(MailMessage $message): array
    {
        $vars = array_merge($this->globalVars, $message->templateVars);
        foreach ($vars as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new InvalidMailMessageException('Mail template variable name is invalid');
            }
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidMailMessageException('Mail template variables must be scalar');
            }
        }

        return $vars;
    }
}
