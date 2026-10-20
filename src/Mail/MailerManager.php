<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail;

use Psr\Log\LoggerInterface;
use Swoolefy\Library\Mail\Config\MailConfig;
use Swoolefy\Library\Mail\Contracts\MailerInterface;
use Swoolefy\Library\Mail\Exception\InvalidMailerException;
use Swoolefy\Library\Mail\Exception\MailTransportException;
use Swoolefy\Library\Mail\Exception\PartialDeliveryException;
use Swoolefy\Library\Mail\Support\EmailMask;
use Swoolefy\Library\Mail\ValueObject\MailResult;

/**
 * 邮件入口。缓存的 Mailer 只有主机、账号和发件人，没有 PHPMailer，也没有当前这封信。
 */
final class MailerManager
{
    private const LOG_ADDRESS_LIMIT = 10;

    /** @var array<string, MailerInterface> 按名字缓存的发信器；不含 PHPMailer、连接或信件状态 */
    private array $mailers = [];

    private MailFactory $factory;

    /**
     * @param array<string, mixed>|MailConfig $config mail.php 内容，可为外层包 mail 的数组
     * @param LoggerInterface|null $logger 只写脱敏信封和结果字段；后端故障不影响发送
     * @param MailFactory|null $factory 单测可注入自定义工厂/模板引擎
     */
    public function __construct(
        private array|MailConfig $config,
        private ?LoggerInterface $logger = null,
        ?MailFactory $factory = null,
    ) {
        if (is_array($this->config)) {
            $this->config = MailConfig::fromArray($this->config);
        }
        $this->factory = $factory ?? new MailFactory();
    }

    /**
     * 按名取 Mailer；null 使用 default_mailer。
     * 同一 Manager 内按名缓存，缓存的是绑定配置的发信器，不含 PHPMailer 或信件状态。
     *
     * @throws InvalidMailerException 名字为空、未配置或配置非法
     */
    public function mailer(?string $name = null): MailerInterface
    {
        $name = $name ?? $this->config->defaultMailer();
        if ($name === '' || !$this->config->hasMailer($name)) {
            throw new InvalidMailerException(sprintf('Mailer [%s] is not configured', $name));
        }
        if (isset($this->mailers[$name])) {
            return $this->mailers[$name];
        }

        $mailer = $this->factory->make($name, $this->config->mailer($name), $this->config);
        $this->mailers[$name] = $mailer;

        return $mailer;
    }

    /**
     * 消息上的 via() 优先，否则用 default_mailer。
     * 从 mailer 解析到投递结束整体纳入 try/catch：所有失败路径都记录日志，原异常原样抛出；
     * 日志本身抛错会被吞掉，不能改变发送结果或覆盖真实异常。
     */
    public function send(MailMessage $message): MailResult
    {
        $startedAt = hrtime(true);
        $name = $message->mailer;
        $transport = null;

        try {
            $name ??= $this->config->defaultMailer();
            $mailer = $this->mailer($name);
            $transport = $mailer->transport();
            $result = $mailer->send($message);
        } catch (\Throwable $e) {
            $this->log($name, $transport, $message, null, $e, $startedAt);
            throw $e;
        }

        $this->log($name, $transport, $message, $result, null, $startedAt);

        return $result;
    }

    /**
     * 暴露只读配置访问器，便于调用方读取 default_mailer / max_recipients 等。
     */
    public function config(): MailConfig
    {
        return $this->config;
    }

    /**
     * 写一条发送日志，字段全部来自脱敏后的信封，不包含正文、附件内容和 BCC 全量。
     * 级别：成功 info；传输失败/部分投递 error（可重试或需人工处理）；其余 warning（重试无意义）。
     * to/cc 最多各取前 10 个；日志后端异常在内部吞掉。
     */
    private function log(
        ?string $name,
        ?string $transport,
        MailMessage $message,
        ?MailResult $result,
        ?\Throwable $error,
        int $startedAt,
    ): void {
        if ($this->logger === null) {
            return;
        }

        try {
            $to = [];
            foreach (array_slice($message->toList(), 0, self::LOG_ADDRESS_LIMIT) as $address) {
                $to[] = EmailMask::mask($address->email);
            }
            $cc = [];
            foreach (array_slice($message->ccList(), 0, self::LOG_ADDRESS_LIMIT) as $address) {
                $cc[] = EmailMask::mask($address->email);
            }

            $from = $this->fromAddress($name, $message);
            $transportFailure = $error instanceof MailTransportException || $error instanceof PartialDeliveryException;
            $level = $error === null ? 'info' : ($transportFailure ? 'error' : 'warning');

            $this->logger->log($level, 'mail send', [
                'mailer' => $name,
                'transport' => $transport,
                'source' => $message->mailer === null ? 'default' : 'message',
                'from' => $from === null ? null : EmailMask::mask($from),
                'to' => $to,
                'cc' => $cc,
                'recipients' => $message->recipientCount(),
                'bcc_count' => count($message->bccList()),
                'template' => $message->template,
                'attachments' => count($message->attachmentList()),
                'embeds' => count($message->embedList()),
                'duration_ms' => round((hrtime(true) - $startedAt) / 1e6, 2),
                'message_id' => $result?->messageId,
                'exception' => $error === null ? null : $error::class,
                'accepted_recipients' => $error instanceof PartialDeliveryException ? $error->acceptedRecipients : null,
            ]);
        } catch (\Throwable) {
            // 日志后端故障不能改变发送结果
        }
    }

    /**
     * 日志里的发件地址：消息上的 from 覆盖优先，否则读 mailer 配置的 from.address。
     * 失败日志可能发生在配置归一化之前，因此同时兼容 from 为地址数组或字符串两种写法。
     */
    private function fromAddress(?string $name, MailMessage $message): ?string
    {
        if ($message->from !== null) {
            return $message->from->email;
        }
        if ($name === null) {
            return null;
        }

        $from = $this->config->mailer($name)['from'] ?? null;
        if (is_array($from) && is_string($from['address'] ?? null)) {
            return $from['address'];
        }

        return is_string($from) ? $from : null;
    }
}
