# Library 6.x Mail 邮件组件技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 命名空间 | `Swoolefy\Library\Mail` |
| 模块目录 | `src/Mail/` |
| 优先级 | P1 |
| 审查修订 | 2026-10-17 |
| 定位 | 多传输（SMTP / sendmail / PHP `mail()`）可配置、多账号、模板占位、附件、HTML/纯文本双份的邮件发送组件 |
| 底层选型 | [`phpmailer/phpmailer:^7`](https://github.com/PHPMailer/PHPMailer)（SMTP AUTH、隐式 TLS、STARTTLS、附件与 DKIM） |

> Mail 使用 PHPMailer，配置化。范式对齐现有 `Manager + Factory + Config::fromArray + Contracts + Exception`。`phpmailer/phpmailer` 是本库硬依赖，内置传输直接 `new PHPMailer`，不做 `class_exists` 探测。以后的可选传输（不写入 `require` 的 HTTP API）再用 `SdkGuard`。

---

## 2. 与 swoolefy 框架的职责边界

| 能力 | 归属 | 说明 |
|---|---|---|
| SMTP 会话、MIME 组装、发送 | **library** `Mail`（PHPMailer） | 本方案核心 |
| 多传输 / 多账号配置 | **library** 解析 `Config/mail.php` | 配置化 |
| DI 实例化 | **框架** `Application::getApp()->get('mail')` | 组件闭包注册 |
| 异步 / 批量 / 失败重试投递 | **框架** `Job`（信封 + Handler + 重试退避 + 死信） | Mail 只提供同步 `send()` |
| 日志 | **框架** `log` | 组件接收 PSR-3。只记 mailer 名、脱敏地址、结果，不记密码和正文 |
| 模板引擎 | 本组件内置 `{{ }}` 占位；Blade/Twig 由业务按接口替换 | 见 §7.4 |

**非目标：**

- 不自研 SMTP 协议，一律委托 PHPMailer。
- 不做收信 / IMAP。
- 不做订阅列表、营销自动化、退订管理。
- 不在 Web 请求里同步循环群发。批量交给 Job / 进程池。

---

## 3. 设计目标与原则

1. **配置驱动多 mailer**：`default_mailer` + 多个 mailer（各自 transport、主机、认证、加密、发件人）。
2. **传输策略化**：`smtp` / `sendmail` / `mail` / `log`。`Factory` 按 `transport` 装配，并提供 `extend()` 注册新传输。
3. **消息对象化**：`MailMessage` 承载 to/cc/bcc、主题、html/text、附件、内嵌图、回复地址。它是请求态，可交给 Job 序列化，但不放进 Manager 缓存。
4. **模板只做占位**：内置渲染器替换 `{{ key }}`。不实现 Blade、`@yield` 或执行 PHP 模板。
5. **协程安全**：一次 `send()` 一个新的 PHPMailer，`SMTPKeepAlive = false`。SMTP 阻塞靠 Swoole hook TCP 协程化，不自建连接池。
6. **失败只走异常**：校验错误、模板错误、传输失败都抛 `MailException` 子类。成功才返回 `MailResult`。调用方不要再判断 `ok === false`。
7. **敏感信息**：密码只来自配置或环境变量。日志、`MailResult`、异常消息都不包含密码、正文和 SMTP 调试原文。

---

## 4. Composer 依赖

```jsonc
{
    "require": {
        "phpmailer/phpmailer": "^7.0"
    }
}
```

| 依赖 | 用途 |
|---|---|
| `phpmailer/phpmailer` | SMTP / `mail()` / sendmail、MIME、附件、内嵌图 |

PHPMailer 7 无额外扩展硬依赖。SMTPS / STARTTLS 用库里已有的 `ext-openssl`。包随 Composer 安装，网关构造时直接 `new \PHPMailer\PHPMailer\PHPMailer(true)`。

`log` 传输不创建 PHPMailer，单测不连 SMTP。这不等于包可以不安装。

---

## 5. 目录结构

```text
src/Mail/
├── MailerManager.php              # DI 入口；按名解析并缓存 Mailer（只含配置，无请求态）
├── MailFactory.php                # match(transport) + extend()
├── MailConfig.php                 # 配置只读访问器（fromArray）
├── MailMessage.php                # 消息 DTO
├── Address.php                    # email + name
├── Contracts/
│   └── MailerInterface.php        # send(MailMessage): MailResult
├── Transport/
│   ├── SmtpTransport.php          # isSMTP + Host/Port/SMTPSecure/SMTPAuth/Timeout
│   ├── SendmailTransport.php      # isSendmail 之后写回可执行文件路径，不带 -bs
│   ├── MailTransport.php          # isMail()，走 PHP mail()
│   └── LogTransport.php           # 不真发；内存记录组装结果
├── PhpMailerMailer.php            # smtp/sendmail/mail 共用：每次 send 新建 PHPMailer
├── Template/
│   ├── TemplateRenderer.php       # {{ var }} / {{{ var }}}
│   └── TemplateEngineInterface.php
├── ValueObject/
│   └── MailResult.php             # 仅成功：messageId
├── Support/
│   ├── AddressGuard.php           # 地址与显示名，拒绝 CR/LF
│   └── AttachmentNormalizer.php   # 路径或二进制 → 附件规格，并计大小
└── Exception/
    ├── MailException.php
    ├── InvalidMailerException.php       # 未知 transport、缺 host/from、加密与端口不匹配
    ├── InvalidMailMessageException.php  # 无收件人、地址非法、主题空、附件超限、头注入
    ├── TemplateNotFoundException.php
    └── MailTransportException.php       # 连接、认证、超时、对端拒绝，一律这一类
```

内置传输没有 `SdkGuard` / `MissingSdkException`。可选的新传输如果依赖未写入 `require` 的包，再在该传输里检测。

---

## 6. 配置设计

### 6.1 `Config/mail.php`

```php
<?php
return [
    'mail' => [
        'default_mailer' => 'dingtalk',

        'mailers' => [
            // 钉钉企业邮箱。password 填三方客户端安全密码，不是登录密码
            'dingtalk' => [
                'transport'  => 'smtp',
                'host'       => 'smtp.em.dingtalk.com',
                'port'       => 465,
                'encryption' => 'ssl',
                'username'   => env('MAIL_DINGTALK_USER'),
                'password'   => env('MAIL_DINGTALK_PASS'),
                'timeout'    => 10,
                'auth'       => true,
                'from'       => ['address' => env('MAIL_DINGTALK_USER'), 'name' => '示例系统'],
            ],
            // QQ 邮箱。password 填网页端生成的 16 位授权码
            'qq' => [
                'transport'  => 'smtp',
                'host'       => 'smtp.qq.com',
                'port'       => 465,
                'encryption' => 'ssl',
                'username'   => env('MAIL_QQ_USER'),
                'password'   => env('MAIL_QQ_PASS'),
                'timeout'    => 10,
                'auth'       => true,
                'from'       => ['address' => env('MAIL_QQ_USER'), 'name' => '示例系统'],
            ],
            // 腾讯企业邮箱。开启安全登录时 password 填客户端专用密码
            'exmail' => [
                'transport'  => 'smtp',
                'host'       => 'smtp.exmail.qq.com',
                'port'       => 465,
                'encryption' => 'ssl',
                'username'   => env('MAIL_EXMAIL_USER'),
                'password'   => env('MAIL_EXMAIL_PASS'),
                'timeout'    => 10,
                'auth'       => true,
                'from'       => ['address' => env('MAIL_EXMAIL_USER'), 'name' => '示例系统'],
            ],
            // 网易个人邮箱 @163.com。@126.com 用 smtp.126.com，@yeah.net 用 smtp.yeah.net，其余字段相同
            'netease' => [
                'transport'  => 'smtp',
                'host'       => 'smtp.163.com',
                'port'       => 465,
                'encryption' => 'ssl',
                'username'   => env('MAIL_NETEASE_USER'),
                'password'   => env('MAIL_NETEASE_PASS'),
                'timeout'    => 10,
                'auth'       => true,
                'from'       => ['address' => env('MAIL_NETEASE_USER'), 'name' => '示例系统'],
            ],
            // 网易企业邮箱。password 填客户端授权密码。旧集群可把 host 改成 smtp.qiye.163.com
            'netease_qiye' => [
                'transport'  => 'smtp',
                'host'       => 'smtphz.qiye.163.com',
                'port'       => 465,
                'encryption' => 'ssl',
                'username'   => env('MAIL_NETEASE_QIYE_USER'),
                'password'   => env('MAIL_NETEASE_QIYE_PASS'),
                'timeout'    => 10,
                'auth'       => true,
                'from'       => ['address' => env('MAIL_NETEASE_QIYE_USER'), 'name' => '示例系统'],
            ],
            // 飞书邮箱。password 填第三方客户端专用密码。465 只走 SSL，不要配 tls
            'feishu' => [
                'transport'  => 'smtp',
                'host'       => 'smtp.feishu.cn',
                'port'       => 465,
                'encryption' => 'ssl',
                'username'   => env('MAIL_FEISHU_USER'),
                'password'   => env('MAIL_FEISHU_PASS'),
                'timeout'    => 10,
                'auth'       => true,
                'from'       => ['address' => env('MAIL_FEISHU_USER'), 'name' => '示例系统'],
            ],
            // PHPMailer isSendmail()。path 只写可执行文件，-i -t 由 PHPMailer 追加。
            // 不要写 -bs：整段路径会经 escapeshellcmd，参数会被转义，且 -bs 与 -t 冲突。
            'sendmail' => [
                'transport' => 'sendmail',
                'path'      => '/usr/sbin/sendmail',
                'from'      => ['address' => 'no-reply@example.com', 'name' => '示例系统'],
            ],
            // PHP mail()。失败原因通常只有 false。仅作本机兜底
            'mail' => [
                'transport' => 'mail',
                'from'      => ['address' => 'no-reply@example.com', 'name' => '示例系统'],
            ],
            'log' => [
                'transport' => 'log',
                'from'      => ['address' => 'log@example.com', 'name' => 'Log'],
            ],
        ],

        'template' => [
            'view_path' => APP_PATH . '/Resource/Mail',   // 只读 {name}.html
            'vars'      => [],                              // 全局变量，消息变量覆盖同名键
        ],

        'attachments' => [
            'max_bytes' => 20 * 1024 * 1024,                // 单封附件 + 内嵌图合计上限
        ],
    ],
];
```

加密与端口的对应关系（配置阶段校验，不匹配抛 `InvalidMailerException`）：

| encryption | PHPMailer | 常用端口 |
|---|---|---|
| `ssl` | `ENCRYPTION_SMTPS`（隐式 TLS） | 465 |
| `tls` | `ENCRYPTION_STARTTLS` | 587 |
| `''` | 不加密 | 25 |

内置业务 mailer 都是 SMTP，差别只在主机和账号。组件不按厂商再写一套协议。

| mailer | 服务 | SMTP | 端口 / 加密 | password 填什么 |
|---|---|---|---|---|
| `dingtalk` | 钉钉企业邮箱 | `smtp.em.dingtalk.com` | 465 / `ssl` | 三方客户端安全密码 |
| `qq` | QQ 邮箱 | `smtp.qq.com` | 465 / `ssl` | 16 位授权码 |
| `exmail` | 腾讯企业邮箱 | `smtp.exmail.qq.com` | 465 / `ssl` | 客户端专用密码 |
| `netease` | 网易个人邮箱 | `smtp.163.com` | 465 / `ssl` | 客户端授权码 |
| `netease_qiye` | 网易企业邮箱 | `smtphz.qiye.163.com` | 465 / `ssl` | 客户端授权密码 |
| `feishu` | 飞书邮箱 | `smtp.feishu.cn` | 465 / `ssl` | 第三方客户端专用密码 |

网易个人邮箱按后缀选主机，配置结构相同：`@163.com` → `smtp.163.com`，`@126.com` → `smtp.126.com`，`@yeah.net` → `smtp.yeah.net`。网易企业邮箱若仍在旧集群，把 host 改成 `smtp.qiye.163.com`。

这几家都不接受邮箱登录密码作为 SMTP 密码，必须先在网页邮箱里打开 SMTP，再生成授权码或专用密码。钉钉邮箱不要用 587：该端口并未对所有 IP 开放；阿里云 ECS 还会封 25，所以默认 465。飞书的 465 只支持 SSL，不支持 STARTTLS。

`from.address` 必须与 `username` 相同。消息上的 `from()` 若改成别的地址，这些服务器会直接拒信。配置阶段两者不一致就抛 `InvalidMailerException`。

`smtp` 必须有 `host`、`port`、`from.address`。`username` / `password` 在 `auth=true` 时必填。`sendmail` 与 `mail` 也必须有 `from.address`，否则 PHPMailer 无法发信。

DKIM 不是首发必做。需要时在对应 mailer 下加可选块，映射到 PHPMailer 的 `DKIM_domain` / `DKIM_selector` / `DKIM_private` / `DKIM_passphrase`。私钥路径只来自配置，不进日志。

未配置 `default_mailer` 时用 `mailers` 的第一个键。一个都没有则抛 `InvalidMailerException`，禁止静默落到 PHP `mail()`。

### 6.2 `MailConfig`

`fromArray()` 兼容外层再包一层 `mail`。提供 `defaultMailer()`、`hasMailer()`、`mailer()`、`templatePath()`、`templateVars()`、`attachmentMaxBytes()`。

### 6.3 DI

```php
<?php
use Swoolefy\Core\Application;
use Swoolefy\Library\Mail\MailerManager;

return [
    'mail' => static function (): MailerManager {
        $c = include APP_PATH . '/Config/mail.php';
        return new MailerManager($c['mail'], Application::getApp()->get('log'));
    },
];
```

---

## 7. 核心类设计

### 7.1 `MailerManager`

缓存的是「某套配置对应的 Mailer」，里面只有 host、账号、发件人。没有 PHPMailer 实例，也没有当前这封信。

```php
final class MailerManager
{
    /** @var array<string, MailerInterface> */
    private array $mailers = [];

    public function __construct(
        private array|MailConfig $config,
        private ?\Psr\Log\LoggerInterface $logger = null,
        private ?MailFactory $factory = null,
    ) {
        if (is_array($this->config)) {
            $this->config = MailConfig::fromArray($this->config);
        }
        $this->factory ??= new MailFactory();
    }

    /** null 用 default_mailer。同名 Mailer 在本 Manager 内复用 */
    public function mailer(?string $name = null): MailerInterface;

    /** 等价于 mailer(null)->send()。消息自己的 mailer 名优先于默认名 */
    public function send(MailMessage $message): MailResult;
}
```

日志由 Manager 在 `send()` 返回或捕获 `MailTransportException` 之后写。Transport 自己不拿 Logger，避免每封信的状态进到缓存对象里。

### 7.2 `MailFactory`

```php
final class MailFactory
{
    /** @var array<string, callable(array<string, mixed>, string): MailerInterface> */
    private array $customCreators = [];

    /** 注册新传输，或在单测里替换某个 transport。已缓存的 Mailer 不会自动换掉 */
    public function extend(string $transport, callable $creator): void;

    public function make(string $name, array $config): MailerInterface
    {
        $transport = (string) ($config['transport'] ?? '');
        if (isset($this->customCreators[$transport])) {
            return ($this->customCreators[$transport])($config, $name);
        }

        return match ($transport) {
            'smtp'     => new PhpMailerMailer(new SmtpTransport($config), $config),
            'sendmail' => new PhpMailerMailer(new SendmailTransport($config), $config),
            'mail'     => new PhpMailerMailer(new MailTransport($config), $config),
            'log'      => new LogTransport($config),
            default    => throw new InvalidMailerException("Unknown mail transport [{$transport}] for [{$name}]"),
        };
    }
}
```

未知 transport 是配置错误，用 `InvalidMailerException`。不要用传输异常，否则 Job 会把写错的配置当成暂时失败反复重试。

`smtp` / `sendmail` / `mail` 的差别只在如何配置那一个 PHPMailer：

| transport | PHPMailer | 行为 |
|---|---|---|
| `smtp` | `isSMTP()` | 直连配置的主机。有认证、加密和超时 |
| `sendmail` | `isSendmail()` | 执行 `path` 指向的程序，PHPMailer 追加 `-i -t`。退出码非 0 时抛运输异常 |
| `mail` | `isMail()` | PHP `mail()`。是否成功往往只看布尔值，投递细节在本机 MTA |

三者不是同一个东西。`mail` 不是「无认证的 sendmail 传输」。

### 7.3 `MailMessage`

可变 DTO，但只在创建它的那一次发送里使用。不要放进协程间共享的缓存后再改。

```php
final class MailMessage
{
    /** @var list<Address> */
    public array $to = [];
    /** @var list<Address> */
    public array $cc = [];
    /** @var list<Address> */
    public array $bcc = [];
    public ?Address $from = null;       // 覆盖该 mailer 的默认发件人
    public ?Address $replyTo = null;
    public string $subject = '';
    public ?string $html = null;
    public ?string $text = null;        // 不传则从 html 抽出纯文本，作为 AltBody
    /** @var list<array{path?: string, data?: string, name?: string, mime?: string}> */
    public array $attachments = [];
    /** @var list<array{path?: string, data?: string, cid: string}> */
    public array $embeds = [];
    public ?string $template = null;
    /** @var array<string, scalar|null> */
    public array $templateVars = [];
    public ?string $mailer = null;      // null 表示用 default_mailer

    public function to(string $email, ?string $name = null): self;   // 追加
    public function cc(string $email, ?string $name = null): self;
    public function bcc(string $email, ?string $name = null): self;
    public function from(string $email, ?string $name = null): self; // 覆盖
    public function replyTo(string $email, ?string $name = null): self;
    public function subject(string $subject): self;
    public function html(string $html): self;
    public function text(string $text): self;
    public function attach(string $path, ?string $asName = null): self;
    public function attachData(string $data, string $asName, ?string $mime = null): self;
    public function embed(string $path, string $cid): self;
    public function viaTemplate(string $name, array $vars = []): self;
    public function via(string $mailer): self;
}
```

投进 Job 时只序列化标量和文件路径。`resource`、闭包、已打开的流不能进队列。二进制正文若必须排队，调用方先落盘，再 `attach(路径)`。

地址校验：

- 用 `PHPMailer::validateAddress()`，不自写宽松正则。
- 邮箱、显示名、主题拒绝包含 `\r` 或 `\n`，避免邮件头注入。
- 同一封信里 to/cc/bcc 按邮箱去重。

### 7.4 模板

内置 `TemplateRenderer`：

- 文件只认 `view_path/{name}.html`。`name` 仅允许 `[A-Za-z0-9_-]`，禁止 `/`、`\`、`.`，从根上去掉路径穿越，也不 `include` PHP。
- `{{ key }}` 替换前做 HTML 转义。`{{{ key }}}` 原样输出，只给调用方明确标记的可信 HTML。
- 消息上的 `templateVars` 覆盖配置里的全局 `vars`。值只接受标量。
- 缺文件抛 `TemplateNotFoundException`。
- 渲染结果写入 `html`。没传 `text` 时，用去掉标签后的文本做 AltBody；`<a href>` 保留 URL。两边都空则抛 `InvalidMailMessageException`。
- 主题仍然必填，模板不负责生成主题。

`TemplateEngineInterface` 只负责「模板名 + 变量 → html 字符串」。Blade / Twig 由业务实现这个接口并注入，不内置它们的语法。

### 7.5 `PhpMailerMailer::send()`

```text
校验收件人、地址、主题、附件总量
 → 若有模板则渲染
 → new PHPMailer(true)          // 异常模式；用完即弃
 → CharSet = UTF-8
 → SMTPDebug = 0                // 调试输出会带 AUTH 对话，必须关闭
 → SMTPKeepAlive = false
 → Timeout = 配置的秒数
 → Transport 设置 isSMTP / isSendmail / isMail 及主机、加密、账号
 → 组装 from / replyTo / to / cc / bcc / subject / html / AltBody / 附件 / 内嵌图
 → send()
 → 成功：MailResult{messageId}
 → PHPMailer 异常：转成 MailTransportException（消息用 ErrorInfo，去掉账号和密码）
```

`MailResult` 只有成功这一条路，`messageId` 取 PHPMailer 的 `getLastMessageID()`。没有 `ok=false` 的失败结果。

`log` 传输走同一套校验和模板渲染，然后把脱敏后的信封放进内存列表并返回成功结果，不创建 PHPMailer。

---

## 8. 用法示例

### 8.1 同步发送

```php
use Swoolefy\Core\Application;
use Swoolefy\Library\Mail\Exception\MailException;
use Swoolefy\Library\Mail\MailMessage;

$mail = Application::getApp()->get('mail');

try {
    $result = $mail->mailer('dingtalk')->send(
        (new MailMessage())
            ->to('user@example.com', '张三')
            ->subject('订单已发货')
            ->html('<p>你的订单 <b>#10086</b> 已发货。</p>')
            ->attach('/data/pdf/invoice-10086.pdf')
    );
    $messageId = $result->messageId;
} catch (MailException $e) {
    // 配置/信件错误不会靠重试变好；传输错误交给上层或 Job 重试
}
```

### 8.2 模板

```php
$msg = (new MailMessage())
    ->to('user@example.com')
    ->subject('请激活账号')
    ->viaTemplate('welcome', ['name' => '张三', 'activate_url' => $url]);

$mail->send($msg);   // Resource/Mail/welcome.html，{{ name }} 会转义
```

### 8.3 异步

Web 请求只把「路径 + 标量」形式的 `MailMessage` 投给框架 Job。`SendMailJob::handle()` 里调用 `Application::getApp()->get('mail')->mailer(...)->send($message)`。

重试只捕获 `MailTransportException`。`InvalidMailerException`、`InvalidMailMessageException`、`TemplateNotFoundException` 直接失败，不重试。

### 8.4 联调

```php
$result = Application::getApp()->get('mail')->mailer('log')->send($msg);
```

---

## 9. 协程与安全

1. PHPMailer 的 SMTP 使用 `stream_socket_client`，不是 cURL。Swoole 需要 hook TCP（框架默认 `SWOOLE_HOOK_ALL` 已包含），这样阻塞点让出协程。没开 hook 时，一次 SMTP 会堵住整个 Worker。
2. 每次 `send()` 新建 PHPMailer，`SMTPKeepAlive` 保持 false。SMTP 会话有状态，不放进连接池，也不跨协程复用。
3. `Timeout` 使用配置秒数，默认建议 10，避免远端挂起一直占着协程。
4. Web 请求里一封信的收件人控制在个位数。批量走 Job。
5. 日志字段：`mailer`、`transport`、脱敏后的 to/cc（`z***@example.com`）、收件人个数、`messageId` 或异常类名。不记 bcc 全量、密码、正文、附件内容、`SMTPDebug`。
6. 附件路径必须是普通文件的真实路径，拒绝 `..` 和目录。读入的字节数（含内嵌图）合计超过 `max_bytes` 抛 `InvalidMailMessageException`。
7. 模板名白名单见 §7.4。变量默认转义。

---

## 10. 异常

| 场景 | 异常 | Job 是否重试 |
|---|---|---|
| 未知 transport、缺 host / from、加密和端口不匹配 | `InvalidMailerException` | 否 |
| 无收件人、地址非法、主题空、头注入、附件超限或不可读 | `InvalidMailMessageException` | 否 |
| 模板名非法或文件不存在 | `TemplateNotFoundException` | 否 |
| 连接失败、认证失败、超时、对端拒绝 | `MailTransportException` | 是 |

PHPMailer 把连接失败和投递拒绝都放在同一个异常的 `ErrorInfo` 里，组件不再按文案拆成两种异常。

统一基类是 `MailException`。业务可以 `catch (MailException)`，Job 只对 `MailTransportException` 重试。

---

## 11. 测试

| 层级 | 用例 | 依赖 |
|---|---|---|
| 纯逻辑 | 地址去重、拒绝 CR/LF、主题为空、附件超限、`from` 覆盖 mailer 默认值 | 无 SMTP |
| 配置 | 外层 `mail` 包裹、缺省 default、465+`tls` 被拒绝、auth 缺密码被拒绝 | 无 |
| 模板 | `{{ }}` 转义、`{{{ }}}` 不转义、`../` 模板名被拒绝、缺文件抛异常、html 抽出 AltBody | 无 |
| log 传输 | 断言 subject、收件人个数、附件个数；确认没有创建外部连接 | 无 |
| 异常消息 | 构造失败信息时不出现 password | 无 |
| SMTP 冒烟 `@group mail` | 可选，指向本地 MailHog 一类服务 | CI 默认跳过 |

默认不连外部 SMTP。

---

## 12. 落地顺序

1. `MailConfig`、`Address`、`MailMessage`、`MailResult` 和校验。
2. `MailFactory`、`LogTransport`、`MailerManager`，先把不连网的链路跑通。
3. `PhpMailerMailer` + `SmtpTransport`（UTF-8、超时、关闭 SMTPDebug、异常映射）。
4. `SendmailTransport`、`MailTransport`、附件与内嵌图。
5. `TemplateRenderer` 与可替换的 `TemplateEngineInterface`。
6. `src/Mail/README.md`，根 README 增加 Mail 一行。

## 13. 兼容性

- 新模块，不改已有类。`phpmailer/phpmailer:^7.0` 写入 `composer.json` 的 `require`。
- 与 Job 的边界：本组件只提供同步 `send()`。
- 新传输实现 `MailerInterface` 后，用 `MailFactory::extend()` 注册，不必改 SMTP 代码。
