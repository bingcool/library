<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Config;

use Swoolefy\Library\Mail\Exception\InvalidMailerException;

/**
 * mail.php 的只读访问器。fromArray() 兼容外层再包一层 mail 键。
 */
final class MailConfig
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config)
    {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['mail']) && is_array($config['mail'])) {
            $config = $config['mail'];
        }

        return new self($config);
    }

    public function defaultMailer(): string
    {
        $default = trim((string) ($this->config['default_mailer'] ?? ''));
        if ($default !== '') {
            return $default;
        }

        $first = array_key_first($this->mailers());
        if ($first === null) {
            throw new InvalidMailerException('Mail default_mailer is not configured');
        }

        return (string) $first;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function mailers(): array
    {
        $mailers = $this->config['mailers'] ?? [];

        return is_array($mailers) ? $mailers : [];
    }

    /**
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

    public function hasMailer(string $name): bool
    {
        return $this->mailer($name) !== [];
    }

    public function templatePath(): string
    {
        $template = $this->config['template'] ?? [];
        if (!is_array($template)) {
            return '';
        }

        return (string) ($template['view_path'] ?? '');
    }

    /**
     * @return array<string, scalar|null>
     */
    public function templateVars(): array
    {
        $template = $this->config['template'] ?? [];
        if (!is_array($template) || !is_array($template['vars'] ?? null)) {
            return [];
        }

        return $template['vars'];
    }

    public function attachmentMaxBytes(): int
    {
        $attachments = $this->config['attachments'] ?? [];
        $max = is_array($attachments) ? (int) ($attachments['max_bytes'] ?? 20 * 1024 * 1024) : 20 * 1024 * 1024;
        if ($max < 1) {
            throw new InvalidMailerException('Mail attachment max_bytes must be at least 1');
        }

        return $max;
    }
}
