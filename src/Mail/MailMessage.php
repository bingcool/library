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
    private array $to = [];

    /** @var list<Address> */
    private array $cc = [];

    /** @var list<Address> */
    private array $bcc = [];

    /** @var array<string, true> */
    private array $recipientKeys = [];

    /** 覆盖 mailer 的默认发件人；null 表示用配置里的 from */
    public ?Address $from = null;

    /** 覆盖 mailer 的默认回复地址；null 表示用配置里的 reply_to */
    public ?Address $replyTo = null;

    /** 主题原文，编译阶段才 trim 和校验 */
    public string $subject = '';

    /** HTML 正文；与 text 同时存在时，text 作为 AltBody */
    public ?string $html = null;

    /** 纯文本正文；为 null 且存在 HTML 时自动抽取 */
    public ?string $text = null;

    /** @var list<array{path?: string, data?: string, name?: string, mime?: string}> */
    private array $attachments = [];

    /** @var list<array{path: string, cid: string}> */
    private array $embeds = [];

    /** 模板名；非 null 时用它渲染 HTML，优先于直接设置的 html */
    public ?string $template = null;

    /** @var array<string, scalar|null> 消息级模板变量，覆盖同名全局变量 */
    private array $templateVars = [];

    /** 消息级 mailer；null 使用 default_mailer */
    public ?string $mailer = null;

    /**
     * 追加收件人。地址在 Address 构造时 trim 并校验；
     * 去重键是 to/cc/bcc 共享的小写邮箱集合，跨三个列表也不会重复投递。
     */
    public function to(string $email, ?string $name = null): self
    {
        $this->addRecipient($this->to, $email, $name);

        return $this;
    }

    /**
     * 追加抄送人，去重规则与 to() 相同。
     */
    public function cc(string $email, ?string $name = null): self
    {
        $this->addRecipient($this->cc, $email, $name);

        return $this;
    }

    /**
     * 追加密送人。BCC 不会进入邮件头，但计入收件人总数和日志的 bcc_count。
     */
    public function bcc(string $email, ?string $name = null): self
    {
        $this->addRecipient($this->bcc, $email, $name);

        return $this;
    }

    /**
     * 覆盖 mailer 配置里的默认发件人。钉钉/QQ/腾讯企业邮/网易/飞书要求与登录账号一致。
     */
    public function from(string $email, ?string $name = null): self
    {
        $this->from = new Address($email, $name ?? '');

        return $this;
    }

    /**
     * 覆盖 mailer 配置里的默认回复地址。
     */
    public function replyTo(string $email, ?string $name = null): self
    {
        $this->replyTo = new Address($email, $name ?? '');

        return $this;
    }

    /**
     * 主题原文；编译阶段会 trim，空主题或含 CR/LF 会被拒绝。
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * HTML 正文。与 text 同时存在时，text 作为 AltBody 原样使用。
     */
    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    /**
     * 纯文本正文。未设置时由 HTML 自动抽取；html 和 text 都为空会被拒绝。
     */
    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /**
     * 追加文件附件。这里只存路径，编译阶段才读取并做大小/可读性/.. 校验，
     * 读到的字节会先快照进内存再交给 PHPMailer，避免发送前文件被替换（TOCTOU）。
     *
     * @param string|null $asName 收件人看到的文件名；null 使用文件 basename
     */
    public function attach(string $path, ?string $asName = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $asName ?? '',
        ];

        return $this;
    }

    /**
     * 追加内存附件。MIME 在入口立即校验，只接受裸 type/subtype，
     * 拒绝参数、CR/LF 和控制字符，避免注入 MIME 段头。
     */
    public function attachData(string $data, string $asName, ?string $mime = null): self
    {
        Support\AddressGuard::assertMimeType($mime ?? '');
        $this->attachments[] = [
            'data' => $data,
            'name' => $asName,
            'mime' => $mime ?? '',
        ];

        return $this;
    }

    /**
     * 内嵌图片（HTML 中通过 cid:xxx 引用）。路径校验和字节快照规则与 attach() 相同。
     */
    public function embed(string $path, string $cid): self
    {
        $this->embeds[] = [
            'path' => $path,
            'cid' => $cid,
        ];

        return $this;
    }

    /**
     * 用模板生成 HTML 正文。变量表整体替换上一次结果；同名键覆盖配置里的全局变量。
     * 变量名必须是合法标识符，值必须是标量或 null。
     *
     * @param array<string, scalar|null> $vars
     */
    public function viaTemplate(string $name, array $vars = []): self
    {
        $this->template = $name;
        $this->templateVars = $vars;

        return $this;
    }

    /**
     * 指定本次发送使用的 mailer；优先于 default_mailer。
     */
    public function via(string $mailer): self
    {
        $this->mailer = $mailer;

        return $this;
    }

    /**
     * @return list<Address> 收件人快照，供编译和日志读取；修改返回值不会影响消息
     */
    public function toList(): array
    {
        return $this->to;
    }

    /**
     * @return list<Address> 抄送人快照
     */
    public function ccList(): array
    {
        return $this->cc;
    }

    /**
     * @return list<Address> 密送人快照；不应写入日志或邮件头
     */
    public function bccList(): array
    {
        return $this->bcc;
    }

    /**
     * @return int 去重后的 to+cc+bcc 总数，用于 max_recipients 校验和日志
     */
    public function recipientCount(): int
    {
        return count($this->to) + count($this->cc) + count($this->bcc);
    }

    /**
     * @return list<array{path?: string, data?: string, name?: string, mime?: string}>
     */
    public function attachmentList(): array
    {
        return $this->attachments;
    }

    /**
     * @return list<array{path: string, cid: string}>
     */
    public function embedList(): array
    {
        return $this->embeds;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function templateVariables(): array
    {
        return $this->templateVars;
    }

    /**
     * 地址经 Address 统一校验后写入指定列表。
     * recipientKeys 跨 to/cc/bcc 共享，同一邮箱只保留首次出现的位置，去重为 O(1)。
     *
     * @param list<Address> $bucket
     */
    private function addRecipient(array &$bucket, string $email, ?string $name): void
    {
        $address = new Address($email, $name ?? '');
        $key = strtolower($address->email);
        if (isset($this->recipientKeys[$key])) {
            return;
        }

        $this->recipientKeys[$key] = true;
        $bucket[] = $address;
    }
}
