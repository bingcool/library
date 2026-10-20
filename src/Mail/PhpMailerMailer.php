<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use Swoolefy\Library\Mail\Contracts\MailerInterface;
use Swoolefy\Library\Mail\Contracts\TransportInterface;
use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;
use Swoolefy\Library\Mail\Exception\MailTransportException;
use Swoolefy\Library\Mail\Exception\PartialDeliveryException;
use Swoolefy\Library\Mail\Support\ErrorRedactor;
use Swoolefy\Library\Mail\Support\MessageCompiler;
use Swoolefy\Library\Mail\Support\PreparedMessage;
use Swoolefy\Library\Mail\ValueObject\MailResult;

/**
 * smtp / sendmail / mail 共用。每次 send() 新建 PHPMailer，用完即弃，不跨协程复用连接。
 * 三类失败分开处理：装配失败（消息问题）、已有收件人被接受的部分投递、可重试的传输失败。
 */
final class PhpMailerMailer implements MailerInterface
{
    /**
     * @param array<string, mixed> $config MailerConfigValidator 归一化后的配置
     * @param MessageCompiler $compiler 模板、附件、收件人上限和 from 一致性的统一入口
     * @param \Closure(): PHPMailer|null $phpMailerFactory 单测注入替身；生产为 null
     * @param ErrorRedactor $redactor 清理 PHPMailer 错误里的账号、密码和 DKIM 口令
     */
    public function __construct(
        private TransportInterface $transport,
        private array $config,
        private MessageCompiler $compiler,
        private ?\Closure $phpMailerFactory = null,
        private ErrorRedactor $redactor = new ErrorRedactor(),
    ) {
    }

    /**
     * 先编译消息，再装配一个全新的 PHPMailer 并同步发送。
     *
     * SMTP 下挂 action_function 统计 DATA 被接受后的成功回调：一旦有收件人已收到正文，
     * 后续失败必须抛 PartialDeliveryException，不能作为可重试的传输错误让 Job 整封重发。
     *
     * @throws InvalidMailMessageException 编译或 PHPMailer 装配阶段失败
     * @throws MailTransportException 尚无收件人被接受时失败，可重试
     * @throws PartialDeliveryException 已有收件人被接受，禁止整封重试
     */
    public function send(MailMessage $message): MailResult
    {
        $prepared = $this->compiler->compile($message, $this->config);
        $mailer = $this->phpMailerFactory !== null
            ? ($this->phpMailerFactory)()
            : new PHPMailer(true);

        try {
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->SMTPDebug = 0;
            $mailer->SMTPKeepAlive = false;
            $mailer->Timeout = (int) ($this->config['timeout'] ?? 10);
            $this->transport->apply($mailer);
            $this->applyDkim($mailer);
            $this->fill($mailer, $prepared);
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            // 装配阶段的失败属于消息本身的问题，重试不会成功
            throw new InvalidMailMessageException($this->redactor->clean($e->getMessage(), $this->secrets()));
        }

        $acceptedRecipients = 0;
        $previousAction = $mailer->action_function;
        if ($this->transport->name() === 'smtp') {
            // PHPMailer 在 DATA 被接受后才触发回调；此时已有收件人真正收到正文
            $mailer->action_function = static function (bool $isSent, ...$args) use (&$acceptedRecipients, $previousAction): void {
                if ($isSent) {
                    $acceptedRecipients++;
                }
                if (is_callable($previousAction)) {
                    try {
                        $previousAction($isSent, ...$args);
                    } catch (\Throwable) {
                        // 观测回调不能影响投递结果
                    }
                }
            };
        }

        try {
            $sent = $mailer->send();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $error = $this->redactor->clean($e->getMessage(), $this->secrets());
            if ($acceptedRecipients > 0) {
                throw new PartialDeliveryException($error, $acceptedRecipients);
            }
            // 不把原始异常串进链里，避免 ErrorInfo 里的口令留在 previous 上
            throw new MailTransportException($error);
        } finally {
            if ($this->transport->name() === 'smtp') {
                $mailer->action_function = $previousAction;
            }
        }

        if ($sent !== true) {
            $error = $this->redactor->clean($mailer->ErrorInfo, $this->secrets());
            $error = $error !== '' ? $error : 'Mail sending failed';
            if ($acceptedRecipients > 0) {
                throw new PartialDeliveryException($error, $acceptedRecipients);
            }
            throw new MailTransportException($error);
        }

        $messageId = $mailer->getLastMessageID();

        return new MailResult($messageId !== '' ? $messageId : $mailer->MessageID, $this->transport());
    }

    /**
     * @return string 底层传输名
     */
    public function transport(): string
    {
        return $this->transport->name();
    }

    /**
     * 把编译结果写进 PHPMailer。附件和内嵌图都用内存字节 API，
     * 不再把路径交给 PHPMailer 二次读取，保证发送内容与限额校验时一致。
     */
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
            $mailer->addStringAttachment($file['data'], $file['name'], PHPMailer::ENCODING_BASE64, $file['mime']);
        }
        foreach ($message->embeds as $image) {
            $mailer->addStringEmbeddedImage($image['data'], $image['cid'], $image['name']);
        }
    }

    /**
     * DKIM 配置在 MailerConfigValidator 已校验完整性和私钥可解析性，
     * 这里只做字段映射；null 表示未启用。
     */
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
     * 需要从错误信息中抹掉的敏感值。
     * 口令/passphrase 一律参与替换；用户名过短会误伤普通单词，因此只替换长度 ≥ 4 的账号。
     *
     * @return list<string>
     */
    private function secrets(): array
    {
        $dkim = is_array($this->config['dkim'] ?? null) ? $this->config['dkim'] : [];
        $username = (string) ($this->config['username'] ?? '');

        $secrets = [
            (string) ($this->config['password'] ?? ''),
            (string) ($dkim['passphrase'] ?? ''),
        ];
        // 过短的账号会误伤错误信息里的普通单词，口令则必须一律抹掉
        if (strlen($username) >= 4) {
            $secrets[] = $username;
        }

        return $secrets;
    }
}
