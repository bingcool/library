# Mail

多账号发信。传输用 PHPMailer：SMTP、本机 sendmail、PHP `mail()`。钉钉企业邮箱、QQ 邮箱、腾讯企业邮箱、网易个人邮箱、网易企业邮箱、飞书邮箱都走 SMTP，差别只在主机和授权码。批量发送交给框架 Job，本组件只提供同步 `send()`。

设计见 [docs/library-6.x-Mail邮件技术方案.md](../../docs/library-6.x-Mail邮件技术方案.md)。

## 和框架的分工

| 能力 | 归属 | 说明 |
|---|---|---|
| 发信、MIME、附件 | **library** `Mail` | 本模块 |
| 读 `Config/mail.php` | **library** `MailConfig` | 支持外层再包一层 `mail` |
| 实例化 | **框架** `Application::getApp()->get('mail')` | 组件闭包注册 |
| 异步 / 批量 / 退避重试 | **框架** `Job` | Mail 只提供同步 `send()` |
| 日志 | **框架** `log` | Manager 注入 PSR-3，只记脱敏字段 |

`MailMessage` 是**请求态**：只在创建它的那一次发送里改，不要放进跨协程共享的缓存。投 Job 时只序列化标量和文件路径，不要带 `resource`、闭包或已打开的流。

> 每请求 / 协程通过 DI `get` 取 `MailerManager` 即可。Manager 里缓存的是「某套 host / 账号 / 发件人」对应的 Mailer，**没有** PHPMailer 实例，也**没有**当前这封信。勿在 Worker 进程内 `static` 长期缓存 Manager（配置 / env 变更后不刷新）。

## 配置

`Config/mail.php`：

```php
<?php
return [
    'mail' => [
        // 多 mailer 时必须显式配置；只有一个 mailer 时省略仍会用该唯一账号
        'default_mailer' => 'dingtalk',

        'mailers' => [
            // 钉钉企业邮箱。password 填三方客户端安全密码，不是登录密码
            'dingtalk' => [
                'transport'  => 'smtp',              // smtp | sendmail | mail | log
                'host'       => 'smtp.em.dingtalk.com',
                'port'       => 465,                 // 1..65535，与 encryption 独立
                'encryption' => 'ssl',               // ssl | tls | ''，不再强制绑端口
                // 'autotls' => false,               // 可选；未配置时仅 encryption=tls 自动开启
                'username'   => env('MAIL_DINGTALK_USER'),
                'password'   => env('MAIL_DINGTALK_PASS'),   // 绝不打日志
                'timeout'    => 10,                    // 连接+读超时（秒），默认 10
                'auth'       => true,                  // false 时可不配 username/password
                'from'       => ['address' => env('MAIL_DINGTALK_USER'), 'name' => '示例系统'],
                // 可选 DKIM（首发可不配）：
                // 'dkim' => [
                //     'domain'     => 'example.com',
                //     'selector'   => 'default',
                //     'private'    => '/path/to/dkim.pem',
                //     'passphrase' => env('MAIL_DKIM_PASS'),
                // ],
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
            // PHP mail()。和 sendmail 传输不是一回事；失败原因通常只有 false。仅作本机兜底
            'mail' => [
                'transport' => 'mail',
                'from'      => ['address' => 'no-reply@example.com', 'name' => '示例系统'],
            ],
            // 联调 / 单测：不创建 PHPMailer，不连 SMTP，内存保留最近 32 封脱敏信封
            'log' => [
                'transport' => 'log',
                'from'      => ['address' => 'log@example.com', 'name' => 'Log'],
            ],
        ],

        'template' => [
            'view_path' => APP_PATH . '/Resource/Mail',   // 只读 {name}.html，name 仅 [A-Za-z0-9_-]
            'vars'      => [],                              // 全局变量；viaTemplate() 的 vars 覆盖同名键
        ],

        'attachments' => [
            'max_bytes' => 20 * 1024 * 1024,                // 单封：附件 + 内嵌图读入字节合计上限
        ],

        'max_recipients' => 100,                            // to+cc+bcc 去重后的总数上限，1..10000
    ],
];
```

端口与加密在创建 mailer 时分别校验：端口必须是 1..65535 的整数，`encryption` 只接受 `ssl`、`tls` 或空字符串。`port`、`timeout`、`max_bytes`、`max_recipients` 都按严格整数解析，`'465abc'`、`'10s'`、`'20MB'` 会直接抛 `InvalidMailerException`，不再静默截断。

| encryption | PHPMailer | 常用端口 |
|---|---|---|
| `ssl` | `ENCRYPTION_SMTPS`（隐式 TLS） | 465 |
| `tls` | `ENCRYPTION_STARTTLS` | 587 |
| `''` | 不加密 | 25 |

`autotls` 未配置时，只有 `encryption=tls` 会开启 `SMTPAutoTLS`；明文端口不会被自动升级。钉钉企业邮箱仍强制 `465 + ssl`，飞书 465 只支持 SSL。

| mailer | 服务 | SMTP | 端口 / 加密 | password 填什么 |
|---|---|---|---|---|
| `dingtalk` | 钉钉企业邮箱 | `smtp.em.dingtalk.com` | 465 / `ssl` | 三方客户端安全密码 |
| `qq` | QQ 邮箱 | `smtp.qq.com` | 465 / `ssl` | 16 位授权码 |
| `exmail` | 腾讯企业邮箱 | `smtp.exmail.qq.com` | 465 / `ssl` | 客户端专用密码 |
| `netease` | 网易个人邮箱 | `smtp.163.com` | 465 / `ssl` | 客户端授权码 |
| `netease_qiye` | 网易企业邮箱 | `smtphz.qiye.163.com` | 465 / `ssl` | 客户端授权密码 |
| `feishu` | 飞书邮箱 | `smtp.feishu.cn` | 465 / `ssl` | 第三方客户端专用密码 |

网易个人邮箱按后缀选主机：`@163.com` → `smtp.163.com`，`@126.com` → `smtp.126.com`，`@yeah.net` → `smtp.yeah.net`。网易企业邮箱若仍在旧集群，把 host 改成 `smtp.qiye.163.com`。

这几家都要先在网页邮箱里打开 SMTP，再生成授权码或专用密码。钉钉邮箱用 465：587 并未对所有 IP 开放，阿里云 ECS 还会封 25。飞书的 465 只支持 SSL。

`from.address` 必须与 `username` 相同（上表这些主机在配置阶段就会校验）；地址会先去掉首尾空白再比较。消息上的 `from()` 若改成别的地址，会抛 `InvalidMailMessageException`。自建 SMTP 允许非邮箱用户名（如 AWS SES 的 `AKIA...`、SendGrid 的 `apikey`），此时不校验两者相同，只拒绝控制字符。

`smtp` 必须有 `host`、`port`、`from.address`。`username` / `password` 在 `auth=true` 时必填；`auth` 接受布尔值以及 `'true'/'false'/'1'/'0'/'yes'/'no'/'on'/'off'`。`sendmail` 与 `mail` 也必须有 `from.address`。

DKIM 要么不配，要么 `domain`、`selector`、可读私钥齐全；`selector` 只允许 `[A-Za-z0-9._-]`，环境有 openssl 时会先解析私钥，解析失败直接拒绝。

`sendmail.path` 必须是绝对路径、可执行普通文件，不能带空格、参数或 `..`。PHPMailer 自己追加 `-i -t`，写成 `/usr/sbin/sendmail -bs` 会抛 `InvalidMailerException`。

新传输（例如 HTTP API 发信）实现 `MailerInterface` 后，用 `MailFactory::extend()` 注册；key 大小写不敏感，`extend('SMTP')` 与 `extend('smtp')` 等价。自定义创建器仍会先经过公共校验，收到的配置里 `from` / `reply_to` 是 `Address` 对象、`dkim` 已归一化。`extend` 优先于内置 `match`，已缓存的 Mailer 不会自动替换。

组件注册（`Config/component/mail.php`，由框架 `loadComponents()` 合并）：

```php
<?php
use Swoolefy\Core\Application;
use Swoolefy\Library\Mail\MailerManager;

return [
    'mail' => static function (): MailerManager {
        $c = include APP_PATH . '/Config/mail.php';
        // 第二个参数传 log：成功 info、传输失败 error、配置/消息错误 warning；日志后端故障不影响发送结果
        return new MailerManager($c['mail'], Application::getApp()->get('log'));
    },
];
```

`MailConfig::fromArray()` 兼容外层再包一层 `mail`。

## 使用

### 同步发送

```php
use Swoolefy\Core\Application;
use Swoolefy\Library\Mail\Exception\MailException;
use Swoolefy\Library\Mail\Exception\MailTransportException;
use Swoolefy\Library\Mail\Exception\PartialDeliveryException;
use Swoolefy\Library\Mail\MailMessage;

$mail = Application::getApp()->get('mail');

try {
    // mailer('qq') 显式账号；省略时用 default_mailer
    $result = $mail->mailer('dingtalk')->send(
        (new MailMessage())
            ->to('user@example.com', '张三')          // to/cc/bcc 按邮箱去重（跨三列表）
            ->subject('订单已发货')                    // 必填；拒绝主题里的 \r \n
            ->html('<p>你的订单 <b>#10086</b> 已发货。</p>')
            ->attach('/data/pdf/invoice-10086.pdf')   // 须为可读普通文件；路径含 .. 拒绝
    );
    // 只有成功才有 MailResult，没有 ok=false
    $messageId = $result->messageId;
} catch (PartialDeliveryException $e) {
    // 部分收件人已收到正文。不要整封重试，按 $e->acceptedRecipients 记录并人工补发
} catch (MailTransportException $e) {
    // 尚无收件人被接受前失败：连接、认证、超时、对端拒绝。异常消息已去掉 password；Job 可以重试
} catch (MailException $e) {
    // 配置、地址、模板、附件。Job 不要重试
}

// 消息级指定 mailer（优先于 default_mailer）
$mail->send(
    (new MailMessage())
        ->via('qq')
        ->to('user@example.com')
        ->subject('通知')
        ->text('纯文本正文')
);
```

一次 `send()` 新建一个 PHPMailer，`SMTPDebug = 0`（调试输出会带 AUTH 对话），`SMTPKeepAlive = false`，字符集 UTF-8。SMTP 走 `stream_socket_client`，需 Swoole hook TCP（默认 `SWOOLE_HOOK_ALL`），否则一次发信会阻塞整个 Worker。

`mail` 传输调用 PHP `mail()`，不受 Swoole stream hook 覆盖；`sendmail` 通过 `popen()` 等子进程，是否让出协程取决于进程 hook。两者在协程中首次使用会写一条 `error_log` 告警，生产协程环境优先用 SMTP，或把发信放到任务进程。

| 场景 | 异常 | Job 是否重试 |
|---|---|---|
| 未知 transport、缺 host / from、端口或加密非法、sendmail 路径非法、DKIM 不完整 | `InvalidMailerException` | 否 |
| 无收件人、超过 `max_recipients`、地址非法、主题空、头注入、附件超限 / 不可读 / MIME 非法 | `InvalidMailMessageException` | 否 |
| 模板名非法、view_path 缺失、文件不存在或不可读、渲染失败 | `TemplateNotFoundException` / `InvalidMailMessageException` | 否 |
| 连接失败、认证失败、超时、对端拒绝（尚无收件人被接受） | `MailTransportException` | 是 |
| SMTP 已接受部分收件人的正文后，其余收件人被拒 | `PartialDeliveryException` | 否（整封重试会重复投递） |

日志按结果分级：成功 `info`，传输失败 `error`，配置 / 消息 / 模板错误 `warning`。字段包括 `mailer`、`transport`、`source`（default / message）、脱敏后的 `from`、前 10 个脱敏 to/cc 加 `recipients` 总数、`template`、`attachments`、`embeds`、`duration_ms`、`message_id` 或异常类名；部分投递时另有 `accepted_recipients`。**不记** bcc 全量、password、正文、附件内容。日志后端抛异常时静默忽略，不改变发送结果。

### 模板

模板文件是 `view_path/{name}.html`，例如 `Resource/Mail/welcome.html`。只有真正调用 `viaTemplate()` 时才要求 `view_path`；空路径不会回退到进程 cwd，非目录、不存在或文件不可读都会抛异常。根目录在首次渲染时解析并缓存，模板内容按 `path + mtime + size + inode` 做进程内缓存（最多 128 个），文件变化后自动重新读取。

- `{{ name }}`：HTML 转义后再输出。
- `{{{ html }}}`：原样输出，只给调用方明确标记的可信 HTML。
- 模板名只允许 `[A-Za-z0-9_-]`，禁止 `/`、`.`，避免路径穿越。
- 主题仍用 `subject()`，模板不生成主题。

```php
$msg = (new MailMessage())
    ->to('user@example.com')
    ->subject('请激活账号')
    ->viaTemplate('welcome', ['name' => '张三', 'activate_url' => $url]);

// send() 等价于 mailer(null)->send()；$msg->mailer 非空时优先于 default_mailer
$mail->send($msg);
```

变量必须是标量。消息上的 `templateVars` 覆盖配置里的全局 `vars`。没传 `text` 时，从 HTML 抽出纯文本作为 AltBody，`<a href>` 会保留 URL。html 与 text 都空则抛 `InvalidMailMessageException`。

需要 Blade / Twig 时，实现 `TemplateEngineInterface` 并在构造 `MailFactory` 时注入，替换内置 `TemplateRenderer`。

### 异步

Web 请求里一封信的收件人控制在个位数；群发走 Job。

```php
// 伪代码：队列里只存可序列化的 MailMessage（路径 + 标量）
// SendMailJob::handle():
Application::getApp()->get('mail')
    ->mailer($message->mailer)   // null 时用 default_mailer
    ->send($message);
```

重试只捕获 `MailTransportException`。`PartialDeliveryException` 表示已有收件人收到正文，不能整封重试。`InvalidMailerException`、`InvalidMailMessageException`、`TemplateNotFoundException` 直接失败。

### 联调

`log` 传输走同一套校验和模板渲染，不创建 PHPMailer，也不投递邮件。首次使用会写一条 `error_log` 告警，提醒生产误配；`LogTransport::sent()` 只保留脱敏后的主题（前 2 个字符 + `***`）和 `subject_length`，不保留明文主题。

```php
$result = Application::getApp()->get('mail')->mailer('log')->send($msg);
// 单测可对 LogTransport::sent() 断言脱敏 subject、to、附件个数等
```

## 常用 API 速查

| 方法 | 说明 |
|---|---|
| `MailerManager::mailer(?string $name)` | 按名取 Mailer；`null` 用 `default_mailer` |
| `MailerManager::send(MailMessage)` | 消息的 `via()` 优先于默认 mailer |
| `MailMessage::to/cc/bcc` | 追加收件人，全列表按邮箱去重（首尾空白先 trim） |
| `MailMessage::toList/ccList/bccList/recipientCount` | 只读访问器；`to/cc/bcc` 等数组已改为 private |
| `MailMessage::from/replyTo` | 覆盖 mailer 默认发件人 / 回复地址 |
| `MailMessage::viaTemplate($name, $vars)` | 渲染 html；`$vars` 整表替换，不与上次 merge |
| `MailMessage::attach($path)` / `attachData()` / `embed($path, $cid)` | 附件与内嵌图；编译时读成字节并做合计上限，之后文件再变也不影响本封 |
| `MailMessage::attachmentList/embedList/templateVariables` | 附件、内嵌图、模板变量的只读访问器 |
| `attachData($data, $name, $mime)` | 内存附件；`$mime` 只接受裸 `type/subtype`，拒绝参数和 CR/LF |
| `MailResult::messageId` | 成功时的 Message-ID（log 传输为 `log-{hex}`） |

## 测试

```bash
./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Mail
```

默认不连接 SMTP。联调优先用 `log` mailer。
