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
    /**
     * @param TemplateEngineInterface $templates 模板引擎，内置或业务注入
     * @param array<string, scalar|null> $globalVars 模板全局变量，消息变量可覆盖同名键
     * @param int $maxBytes 附件 + 内嵌图合计字节上限
     * @param int $maxRecipients to+cc+bcc 去重后的总数上限
     * @param AttachmentNormalizer $attachments 附件读取与快照
     */
    public function __construct(
        private TemplateEngineInterface $templates,
        private array $globalVars,
        private int $maxBytes,
        private int $maxRecipients = 100,
        private AttachmentNormalizer $attachments = new AttachmentNormalizer(),
    ) {
    }

    /**
     * 把 MailMessage 编译成只读 PreparedMessage，log 与 PHPMailer 传输共用。
     * 校验顺序：收件人数量 → 主题 → 模板/正文 → from 一致性 → 附件读取。
     * 模板渲染结果为空时按纯文本处理；缺 text 时从 HTML 自动抽取 AltBody。
     *
     * @param array<string, mixed> $mailerConfig MailerConfigValidator 归一化后的配置
     * @throws InvalidMailMessageException 收件人、主题、正文、from 或附件不合法
     */
    public function compile(MailMessage $message, array $mailerConfig): PreparedMessage
    {
        $recipients = $message->recipientCount();
        if ($recipients === 0) {
            throw new InvalidMailMessageException('Mail message requires at least one recipient');
        }
        if ($recipients > $this->maxRecipients) {
            throw new InvalidMailMessageException(sprintf(
                'Mail recipients exceed the limit of %d',
                $this->maxRecipients
            ));
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
            $message->toList(),
            $message->ccList(),
            $message->bccList(),
            $subject,
            $html,
            $text,
            $files['attachments'],
            $files['embeds'],
        );
    }

    /**
     * 合并全局变量与消息变量（消息优先），并校验变量名是合法标识符、值是标量或 null。
     * 在渲染前统一拒绝，避免模板回调里出现难以定位的类型错误。
     *
     * @return array<string, scalar|null>
     */
    private function vars(MailMessage $message): array
    {
        $vars = array_merge($this->globalVars, $message->templateVariables());
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
