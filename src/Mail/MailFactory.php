<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail;

use Swoolefy\Library\Mail\Config\MailConfig;
use Swoolefy\Library\Mail\Contracts\MailerInterface;
use Swoolefy\Library\Mail\Exception\InvalidMailerException;
use Swoolefy\Library\Mail\Support\MailerConfigValidator;
use Swoolefy\Library\Mail\Support\MessageCompiler;
use Swoolefy\Library\Mail\Template\TemplateEngineInterface;
use Swoolefy\Library\Mail\Template\TemplateRenderer;
use Swoolefy\Library\Mail\Transport\LogTransport;
use Swoolefy\Library\Mail\Transport\MailTransport;
use Swoolefy\Library\Mail\Transport\SendmailTransport;
use Swoolefy\Library\Mail\Transport\SmtpTransport;

/**
 * 按 transport 装配发信器。内置 smtp / sendmail / mail / log。
 * extend() 优先，用来加新传输或在单测里替换某一类。
 */
final class MailFactory
{
    /**
     * @var array<string, callable(array<string, mixed>, string): MailerInterface>
     */
    private array $customCreators = [];

    public function __construct(private ?TemplateEngineInterface $templates = null)
    {
    }

    /**
     * @param callable(array<string, mixed>, string): MailerInterface $creator
     */
    public function extend(string $transport, callable $creator): void
    {
        $this->customCreators[$transport] = $creator;
    }

    /**
     * @param array<string, mixed> $config 单个 mailer 配置块
     */
    public function make(string $name, array $config, MailConfig $root): MailerInterface
    {
        $transport = strtolower(trim((string) ($config['transport'] ?? '')));
        if (isset($this->customCreators[$transport])) {
            return ($this->customCreators[$transport])($config, $name);
        }

        $normalized = MailerConfigValidator::normalize($name, $config);
        $compiler = new MessageCompiler(
            $this->templates ?? new TemplateRenderer($root->templatePath()),
            $root->templateVars(),
            $root->attachmentMaxBytes(),
        );

        return match ($normalized['transport']) {
            'smtp' => new PhpMailerMailer(new SmtpTransport($normalized), $normalized, $compiler),
            'sendmail' => new PhpMailerMailer(new SendmailTransport($normalized), $normalized, $compiler),
            'mail' => new PhpMailerMailer(new MailTransport($normalized), $normalized, $compiler),
            'log' => new LogTransport($normalized, $compiler),
            default => throw new InvalidMailerException(sprintf('Unknown mail transport [%s] for [%s]', $transport, $name)),
        };
    }
}
