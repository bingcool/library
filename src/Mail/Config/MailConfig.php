<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Config;

use Swoolefy\Library\Mail\Exception\InvalidMailerException;
use Swoolefy\Library\Mail\Support\ConfigValue;

/**
 * mail.php 的只读访问器。fromArray() 兼容外层再包一层 mail 键。
 */
final class MailConfig
{
    /**
     * @param array<string, mixed> $config 已去掉外层 mail 的配置数组
     */
    public function __construct(private array $config)
    {
    }

    /**
     * 兼容 Config/mail.php 外层再包一层 mail 的写法。
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['mail']) && is_array($config['mail'])) {
            $config = $config['mail'];
        }

        return new self($config);
    }

    /**
     * 解析默认 mailer。
     * 显式 default_mailer 必须存在；未配置时只允许在单 mailer 情况下回退，
     * 多 mailer 直接拒绝，避免数组顺序变化导致静默换发件账号。
     */
    public function defaultMailer(): string
    {
        $mailers = $this->mailers();
        $default = trim((string) ($this->config['default_mailer'] ?? ''));
        if ($default !== '') {
            if (!isset($mailers[$default]) || !is_array($mailers[$default])) {
                throw new InvalidMailerException(sprintf('Mail default_mailer [%s] is not configured', $default));
            }

            return $default;
        }

        if ($mailers === []) {
            throw new InvalidMailerException('Mail default_mailer is not configured');
        }
        if (count($mailers) === 1) {
            return (string) array_key_first($mailers);
        }

        throw new InvalidMailerException('Mail default_mailer must be configured when multiple mailers exist');
    }

    /**
     * @return array<string, array<string, mixed>> 原始 mailers 配置，不做归一化
     */
    public function mailers(): array
    {
        $mailers = $this->config['mailers'] ?? [];

        return is_array($mailers) ? $mailers : [];
    }

    /**
     * 取单个 mailer 配置；不存在或形状不对时返回空数组，由调用方决定是否报错。
     *
     * @return array<string, mixed>
     */
    public function mailer(string $name): array
    {
        $mailers = $this->mailers();
        if (!isset($mailers[$name]) || !is_array($mailers[$name])) {
            return [];
        }

        return $mailers[$name];
    }

    /**
     * 判断 mailer 是否存在且为数组配置。
     */
    public function hasMailer(string $name): bool
    {
        return $this->mailer($name) !== [];
    }

    /**
     * @return string 模板根目录原始配置；为空表示未配置，TemplateRenderer 不会回退到 cwd
     */
    public function templatePath(): string
    {
        $template = $this->config['template'] ?? [];
        if (!is_array($template)) {
            return '';
        }

        return trim((string) ($template['view_path'] ?? ''));
    }

    /**
     * @return array<string, scalar|null> 模板全局变量；消息上的同名变量会覆盖这里
     */
    public function templateVars(): array
    {
        $template = $this->config['template'] ?? [];
        if (!is_array($template) || !is_array($template['vars'] ?? null)) {
            return [];
        }

        return $template['vars'];
    }

    /**
     * @return int 单封邮件附件与内嵌图合计上限，严格整数解析且至少 1 字节
     */
    public function attachmentMaxBytes(): int
    {
        $attachments = $this->config['attachments'] ?? [];
        $value = is_array($attachments) ? ($attachments['max_bytes'] ?? 20 * 1024 * 1024) : 20 * 1024 * 1024;
        $max = ConfigValue::integer($value, 'attachments.max_bytes');
        if ($max < 1) {
            throw new InvalidMailerException('Mail attachment max_bytes must be at least 1');
        }

        return $max;
    }

    /**
     * @return int to+cc+bcc 去重后的收件人上限，默认 100，允许 1..10000
     */
    public function maxRecipients(): int
    {
        $max = ConfigValue::integer($this->config['max_recipients'] ?? 100, 'max_recipients');
        if ($max < 1 || $max > 10000) {
            throw new InvalidMailerException('Mail max_recipients must be between 1 and 10000');
        }

        return $max;
    }
}
