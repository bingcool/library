<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail;

/**
 * 一封待发的信。只在创建它的这次发送里修改，不要放进跨协程共享的缓存。
 * to/cc/bcc 按邮箱追加并去重；from 和 replyTo 是覆盖。
 */
final class MailMessage
{
    /** @var list<Address> */
    public array $to = [];

    /** @var list<Address> */
    public array $cc = [];

    /** @var list<Address> */
    public array $bcc = [];

    public ?Address $from = null;

    public ?Address $replyTo = null;

    public string $subject = '';

    public ?string $html = null;

    public ?string $text = null;

    /** @var list<array{path?: string, data?: string, name?: string, mime?: string}> */
    public array $attachments = [];

    /** @var list<array{path: string, cid: string}> */
    public array $embeds = [];

    public ?string $template = null;

    /** @var array<string, scalar|null> */
    public array $templateVars = [];

    public ?string $mailer = null;

    public function to(string $email, ?string $name = null): self
    {
        $this->addRecipient($this->to, $email, $name);

        return $this;
    }

    public function cc(string $email, ?string $name = null): self
    {
        $this->addRecipient($this->cc, $email, $name);

        return $this;
    }

    public function bcc(string $email, ?string $name = null): self
    {
        $this->addRecipient($this->bcc, $email, $name);

        return $this;
    }

    public function from(string $email, ?string $name = null): self
    {
        $this->from = new Address($email, $name ?? '');

        return $this;
    }

    public function replyTo(string $email, ?string $name = null): self
    {
        $this->replyTo = new Address($email, $name ?? '');

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function attach(string $path, ?string $asName = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $asName ?? '',
        ];

        return $this;
    }

    public function attachData(string $data, string $asName, ?string $mime = null): self
    {
        $this->attachments[] = [
            'data' => $data,
            'name' => $asName,
            'mime' => $mime ?? '',
        ];

        return $this;
    }

    public function embed(string $path, string $cid): self
    {
        $this->embeds[] = [
            'path' => $path,
            'cid' => $cid,
        ];

        return $this;
    }

    /**
     * @param array<string, scalar|null> $vars
     */
    public function viaTemplate(string $name, array $vars = []): self
    {
        $this->template = $name;
        $this->templateVars = $vars;

        return $this;
    }

    public function via(string $mailer): self
    {
        $this->mailer = $mailer;

        return $this;
    }

    /**
     * @param list<Address> $bucket
     */
    private function addRecipient(array &$bucket, string $email, ?string $name): void
    {
        $address = new Address($email, $name ?? '');
        $key = strtolower($address->email);
        foreach ([$this->to, $this->cc, $this->bcc] as $list) {
            foreach ($list as $existing) {
                if (strtolower($existing->email) === $key) {
                    return;
                }
            }
        }

        $bucket[] = $address;
    }
}
