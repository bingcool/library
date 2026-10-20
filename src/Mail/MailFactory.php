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
     * 小写 transport => 创建器，优先于内置 match。
     *
     * @var array<string, callable(array<string, mixed>, string): MailerInterface>
     */
    private array $customCreators = [];

    /**
     * @param TemplateEngineInterface|null $templates 自定义模板引擎；null 使用内置 TemplateRenderer
     */
    public function __construct(private ?TemplateEngineInterface $templates = null)
    {
    }

    /**
     * 注册自定义 transport，优先于内置 match。
     * key 统一小写去空白；创建器收到的是 normalizeCommon() 后的配置，传输专属字段需自行校验。
     * 已经由 Manager 缓存的 Mailer 不会因为再次 extend() 而替换。
     *
     * @param callable(array<string, mixed>, string): MailerInterface $creator
     */
    public function extend(string $transport, callable $creator): void
    {
        $this->customCreators[strtolower(trim($transport))] = $creator;
    }

    /**
     * 按 transport 创建发信器。
     * 内置传输先完整归一化（host/port/from/auth/DKIM 等），再用同一份模板根、
     * 全局变量、附件上限和收件人上限创建 MessageCompiler。
     *
     * @param array<string, mixed> $config 单个 mailer 配置块
     * @throws InvalidMailerException 未知 transport 或配置非法
     */
    public function make(string $name, array $config, MailConfig $root): MailerInterface
    {
        $transport = strtolower(trim(is_string($config['transport'] ?? null) ? $config['transport'] : ''));
        if (isset($this->customCreators[$transport])) {
            return ($this->customCreators[$transport])(MailerConfigValidator::normalizeCommon($name, $config), $name);
        }

        $normalized = MailerConfigValidator::normalize($name, $config);
        $compiler = new MessageCompiler(
            $this->templates ?? new TemplateRenderer($root->templatePath()),
            $root->templateVars(),
            $root->attachmentMaxBytes(),
            $root->maxRecipients(),
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
