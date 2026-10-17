<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail;

use Psr\Log\LoggerInterface;
use Swoolefy\Library\Mail\Config\MailConfig;
use Swoolefy\Library\Mail\Contracts\MailerInterface;
use Swoolefy\Library\Mail\Exception\InvalidMailerException;
use Swoolefy\Library\Mail\Exception\MailException;
use Swoolefy\Library\Mail\Exception\MailTransportException;
use Swoolefy\Library\Mail\Support\EmailMask;
use Swoolefy\Library\Mail\ValueObject\MailResult;

/**
 * 邮件入口。缓存的 Mailer 只有主机、账号和发件人，没有 PHPMailer，也没有当前这封信。
 */
final class MailerManager
{
    /** @var array<string, MailerInterface> */
    private array $mailers = [];

    private MailFactory $factory;

    /**
     * @param array<string, mixed>|MailConfig $config
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
     */
    public function send(MailMessage $message): MailResult
    {
        $name = $message->mailer ?? $this->config->defaultMailer();
        $mailer = $this->mailer($name);

        try {
            $result = $mailer->send($message);
            $this->log($name, $mailer->transport(), $message, $result, null);

            return $result;
        } catch (MailTransportException $e) {
            $this->log($name, $mailer->transport(), $message, null, $e);
            throw $e;
        }
    }

    public function config(): MailConfig
    {
        return $this->config;
    }

    private function log(
        string $name,
        string $transport,
        MailMessage $message,
        ?MailResult $result,
        ?MailException $error,
    ): void {
        if ($this->logger === null) {
            return;
        }

        $to = [];
        foreach ($message->to as $address) {
            $to[] = EmailMask::mask($address->email);
        }
        $cc = [];
        foreach ($message->cc as $address) {
            $cc[] = EmailMask::mask($address->email);
        }

        $this->logger->info('mail send', [
            'mailer' => $name,
            'transport' => $transport,
            'to' => $to,
            'cc' => $cc,
            'recipients' => count($message->to) + count($message->cc) + count($message->bcc),
            'bcc_count' => count($message->bcc),
            'message_id' => $result?->messageId,
            'exception' => $error === null ? null : $error::class,
        ]);
    }
}
