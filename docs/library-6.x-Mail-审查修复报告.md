# library-6.x Mail 模块审查修复报告

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块 | Mail（`src/Mail/**/*.php`） |
| 审查日期 | 2026-10-20 |
| 审查范围（审查时） | 29 个 PHP 文件、1594 行；`src/Mail/README.md` 304 行；`docs/library-6.x-Mail邮件技术方案.md` 540 行；`tests/Mail/MailModuleTest.php` 459 行 |
| 修复后规模 | 32 个 PHP 文件、2081 行；新增 `tests/Mail/P1RegressionTest.php`（302 行）、`tests/Mail/P2RegressionTest.php`（738 行） |
| 依赖版本 | `phpmailer/phpmailer` `~7.1`（`composer.json:55`，实际 7.1.1）；PSR-3 Logger |
| 运行环境 | 宿主机 PHP 8.4.14 / Swoole 6.1.7；容器 `swoolefy-php85-v62`（镜像 `swoolefy-php85-swoole62:v1`）PHP 8.5.8 / Swoole 6.2.2；PHPUnit 11.5.57 |
| 基线测试 | 审查时：`OK (22 tests, 64 assertions)`。P1 修复后：`OK (34 tests, 79 assertions)`。P2 修复后：宿主机 `OK (60 tests, 186 assertions)`，容器内同一命令通过 |
| 审查方式 | 逐文件通读 + PHPMailer 7.1.1 vendor 源码对照 + 定向运行时复现 + 假 SMTP 服务端复现 |
| 源码变更 | 审查时无。P1/P2 修复已修改 `src/Mail` 并新增 `tests/Mail/P1RegressionTest.php`、`tests/Mail/P2RegressionTest.php`，详见 §4.1、§5.1 |
| 修复日期 | 2026-10-20（P1 + P2） |
| 复现脚本 | `/tmp/mail-review-repro.php`、`/tmp/fake-smtp.php`、`/tmp/mail-partial-client.php`（临时脚本，未纳入仓库） |

**结论摘要**

- **P0：0 项**。未发现可未授权利用的 RCE、任意文件写入、认证绕过或顶层 SMTP 头注入。
- **P1：4 项，均已修复**。部分投递失败被当成可重试异常导致重复发信；附件 MIME 类型可注入 MIME 段头；日志后端异常会改变发送结果并掩盖真实异常；非邮箱格式的 SMTP 用户名被拒绝，阻断 SES/SendGrid 等服务。
- **P2 / 急需优化：20 项，均已修复**。SMTP 端口与加密解耦、仅 CC/BCC 邮件、严格配置解析、DKIM 完整性、附件实际字节、短口令脱敏、日志全覆盖、模板路径与缓存、收件人上限、协程阻塞告警等均已落地，详见 §5.1。
- 审查时 22 个单测全部通过，但没有覆盖上述 P1/P2 场景；P1 修复新增 12 个回归用例，P2 修复新增 26 个回归用例，当前 60 个单测 / 186 个断言。宿主机因无 procfs 跳过 1 个容器专项用例；容器内全部执行。本报告所有「已复现」结论均来自当前代码与 PHPMailer 7.1.1 的实际行为，不是仅凭静态猜测。

---

## 2. 问题总览

| 等级 | ID | 问题 | 位置 | 核实结果 | 状态 |
|---|---|---|---|---|---|
| P0 | — | 未确认 P0 级问题 | — | 见 §3 | — |
| P1 | MAIL-01 | 部分收件人被拒后仍已向其余人投递，异常却被标记为可重试，Job 重试会重复发信 | `PhpMailerMailer.php:49-54`、`MailerManager.php:64-72`、PHPMailer `smtpSend()` | 已用假 SMTP 复现 | 已修复 |
| P1 | MAIL-02 | `attachData()` 的 MIME 类型未校验，CR/LF 可注入 MIME 段头 | `MailMessage.php:111-120`、`AttachmentNormalizer.php:29-46`、`PhpMailerMailer.php:90-101` | 已复现 | 已修复 |
| P1 | MAIL-03 | 日志后端抛异常会改变发送结果：成功后抛错导致调用方重试；失败时覆盖原 `MailTransportException` | `MailerManager.php:64-72`、`MailerManager.php:100-109` | 已复现 | 已修复 |
| P1 | MAIL-04 | SMTP `username` 被强制要求为邮箱地址，SES `AKIA...`、SendGrid `apikey` 等无法配置；异常类型也不对 | `MailerConfigValidator.php:97-99`、`AddressGuard.php:15-24` | 已复现 | 已修复 |
| P2 | MAIL-05 | `ssl/tls/明文` 与 `465/587/25` 硬绑定，MailHog 1025、STARTTLS 2525/25、隐式 TLS 587 等无法配置 | `MailerConfigValidator.php:72-85`、`SmtpTransport.php:35-41` | 已复现 | 已修复 |
| P2 | MAIL-06 | 仅 CC/BCC 的邮件被模块拒绝，PHPMailer 本身支持 BCC-only | `MessageCompiler.php:30-32` | 已复现 | 已修复 |
| P2 | MAIL-07 | `auth => 'false'` 被 `(bool)` 转成 `true`，错误要求账号密码 | `MailerConfigValidator.php:91` | 已复现 | 已修复 |
| P2 | MAIL-08 | `$mailer->send()` 返回值被忽略，返回 `false` 时仍构造成功结果 | `PhpMailerMailer.php:49-58` | 已用替身复现 | 已修复 |
| P2 | MAIL-09 | `transport->apply()`、`applyDkim()`、`fill()` 在 `try` 外，PHPMailer 异常会以原始类型逃逸，绕过 `MailException` 契约和脱敏 | `PhpMailerMailer.php:45-54` | 已用子类复现 | 已修复 |
| P2 | MAIL-10 | DKIM 只配一半会被静默关闭；`selector` 可为空；私钥未校验可读性 | `MailerConfigValidator.php:137-161`、PHPMailer `:1810-1815` | 已复现 | 已修复 |
| P2 | MAIL-11 | sendmail 路径按空白切分，路径含空格时被静默截断；路径存在性/可执行性未校验 | `MailerConfigValidator.php:123-131`、`tests/Mail/MailModuleTest.php:110-119` | 已复现 | 已修复 |
| P2 | MAIL-12 | `Address` 保存原始邮箱字符串，前后空格导致已知服务商 `from` 匹配失败、去重失效 | `Address.php:12-17`、`MailerConfigValidator.php:102` | 已复现 | 已修复 |
| P2 | MAIL-13 | 未配置 `template.view_path` 时 `realpath('')` 回退到当前工作目录，模板从 cwd 读取 | `MailConfig.php:76-84`、`TemplateRenderer.php:26-29` | 已复现 | 已修复 |
| P2 | MAIL-14 | 附件大小用 `filesize() ?: 0` 统计，stat 为 0 的特殊文件可绕过上限；文档称「读入字节」但实际未读 | `AttachmentNormalizer.php:41`、`:57` | 容器内已复现 | 已修复 |
| P2 | MAIL-15 | `ErrorRedactor` 跳过长度 < 4 的密钥，3 位密码会原样出现在异常消息 | `ErrorRedactor.php:16-26` | 已复现 | 已修复 |
| P2 | MAIL-16 | 只记录 `MailTransportException`；配置/消息/模板异常、mailer 解析失败均无日志；无耗时、来源、模板名等字段 | `MailerManager.php:61-72`、`:80-110` | 代码确认 | 已修复 |
| P2 | MAIL-17 | `port`、`timeout`、`max_bytes` 直接 `(int)`，`'20MB'` 变 20、`'10s'` 变 10、`'465abc'` 变 465 | `MailerConfigValidator.php:66`、`:106`、`MailConfig.php:99-108` | 已复现 | 已修复 |
| P2 | MAIL-18 | `extend()` 的 key 未转小写，`extend('SMTP')` 永远不生效；自定义创建器绕过 `normalize()` 全部校验 | `MailFactory.php:37-40`、`:45-52` | 代码确认（现有单测依赖该行为） | 已修复 |
| P2 | MAIL-19 | `MailMessage` 的收件人、附件、模板变量都是 public 可写数组，可绕过 `Address` 校验和 MIME 校验，错误形状会产生非 `MailException` | `MailMessage.php:13-43` | 代码确认 | 已修复 |
| P2 | MAIL-20 | `sendmail` / `mail` 传输在协程 Worker 中可能阻塞；README 只提示了 SMTP hook | `SendmailTransport.php:23-27`、`MailTransport.php:22-25`、PHPMailer `:894-897`、`:1941-1969` | 代码 + vendor 确认 | 已修复 |
| P2 | MAIL-21 | 未配置 `default_mailer` 时静默使用 `mailers` 第一个键，新增账号可能改变默认发件账号 | `MailConfig.php:33-46` | 代码 + 文档确认 | 已修复 |
| P2 | MAIL-22 | 收件人数量无上限；去重为 O(n²)，大量 BCC 会放大 CPU、SMTP 事务和日志体积 | `MessageCompiler.php:28-32`、`MailMessage.php:153-166` | 代码确认 | 已修复 |
| P2 | MAIL-23 | `log` 传输返回成功和伪 Message-ID；生产误配 `transport=log` 会静默吞信；`sent()` 保留明文主题 | `LogTransport.php:32-58` | 代码确认 | 已修复 |
| P2 | MAIL-24 | 模板读取/Pcre 失败时静默回退原始 HTML；每次 send 重新读文件，无缓存；`file_get_contents` 可能发 warning | `TemplateRenderer.php:26-53` | 代码确认 | 已修复 |

---

## 3. P0 结论

本次审查**未确认 P0 级问题**，判断依据如下：

1. **不存在未授权的代码执行或文件写入**。附件只读；模板路径有名称白名单和 `realpath` 前缀校验；DKIM 私钥路径来自配置，不接受消息级输入。
2. **不存在顶层 SMTP 头注入**。收件人、发件人、显示名、主题、附件名都拒绝 CR/LF；唯一未校验的 `mime` 进入的是 MIME body 的段头，影响范围低于 envelope 头，故定为 P1（MAIL-02）。
3. **不存在认证绕过**。SMTP 认证配置只由服务端配置决定，消息无法覆盖 `username/password/host`。
4. **MAIL-01 是重复投递而非静默丢失**。PHPMailer 在 DATA 被接受后才发现部分 RCPT 失败；重试会重复发给已成功的人，但不会让全部收件人丢失。对验证码/通知类业务仍可能造成用户困惑或重复副作用，因此列为 P1，而不是 P0。
5. **MAIL-03 是控制流污染**。日志异常会把「已发送成功」变成调用方异常，诱发重试和重复投递；它不直接破坏邮件内容或凭据，故定为 P1。
6. **MAIL-04 是配置兼容性阻断**。它让通用 SMTP 服务（SES/SendGrid 等）无法接入，影响面大，但不影响已支持的国内邮箱配置，故定为 P1。

> 如果 Mail 被用于密码重置、支付通知等「重复发送有业务副作用」的场景，MAIL-01 与 MAIL-03 应被视为发布阻断项，优先于其余 P2。

---

## 4. P1 问题详情

### MAIL-01 部分收件人被拒后重试会造成重复投递

**位置**

- `src/Mail/PhpMailerMailer.php:49-54`
- `src/Mail/MailerManager.php:64-72`
- `src/Mail/Exception/MailTransportException.php`（注释明确「Job 可以重试」）
- `vendor/phpmailer/phpmailer/src/PHPMailer.php:2287-2306`

**代码现状**

```php
try {
    $mailer->send();
} catch (\PHPMailer\PHPMailer\Exception $e) {
    throw new MailTransportException($this->redactor->clean($e->getMessage(), $this->secrets()));
}
```

所有 PHPMailer 异常都被映射为 `MailTransportException`，包括「部分收件人被拒」。README 与设计文档都声明 `MailTransportException` 可以由 Job 重试。

**PHPMailer 行为**

`smtpSend()` 的处理顺序是：

1. 对每个收件人发送 `RCPT TO`，失败的放入 `$bad_rcpt`；
2. 只要存在至少一个可用收件人，继续发送 `DATA` 和完整邮件正文；
3. 正文被接受后，才检查 `$bad_rcpt` 并抛出 `recipients_failed`（`PHPMailer.php:2287-2306`）。

因此收到 `MailTransportException` 时，**邮件可能已经投递给部分收件人**，并不是「整封失败」。

**复现**

假 SMTP 服务端：`good@example.com` 返回 `250`，`bad@example.com` 返回 `550`，`DATA` 返回 `354` 并在正文后返回 `250`。

```
RCPT TO:<good@example.com> -> 250
RCPT TO:<bad@example.com>  -> 550 No such user
DATA                       -> 354
<full message>             -> 250
```

组件输出：

```
MailTransportException: SMTP Error: The following recipients failed: bad@example.com: No such user
```

按 README 的重试策略再次发送同一封邮件，`good@example.com` 会收到第二封。

**影响**

- 验证码、订单、支付、告警类邮件重复发送；
- 调用方无法区分「全部失败」「部分成功」「全部成功」；
- Job 自动重试会把一次部分失败放大成多次重复投递；
- 日志只记录异常类名，无法知道哪些地址已被接受。

**修复建议**

1. 新增 `PartialDeliveryException extends MailException`，明确表示「部分收件人已投递，禁止整封重试」，并尽量携带被拒地址/已接受数量。
2. 在 `PhpMailerMailer` 中识别 PHPMailer 的 `recipients_failed`（异常消息前缀或异常码 `STOP_CONTINUE`），映射为上述异常，不要映射为可重试的 `MailTransportException`。
3. 如果产品确实需要重试，只能由业务层针对被拒地址构造新消息，不能自动重发整封。
4. 在 README/技术方案的重试表中补充「部分投递」一行，明确 Job 不得重试。
5. 补一个假 SMTP 回归测试：一个好地址、一个坏地址，断言异常类型、断言不会自动重试。

> 说明：PHPMailer 没有公开 `$bad_rcpt`，只能从异常消息或错误码判断；建议锁定 `~7.1` 的前提下做兼容封装，并在升级 PHPMailer 时跑该回归测试。

---

### MAIL-02 附件 MIME 类型可注入 MIME 段头

**位置**

- `src/Mail/MailMessage.php:111-120`
- `src/Mail/Support/AttachmentNormalizer.php:29-46`
- `src/Mail/PhpMailerMailer.php:90-101`
- `vendor/phpmailer/phpmailer/src/PHPMailer.php:3567-3578`

**代码现状**

`attachData()` 原样保存 `$mime`：

```php
public function attachData(string $data, string $asName, ?string $mime = null): self
{
    $this->attachments[] = [
        'data' => $data,
        'name' => $asName,
        'mime' => $mime ?? '',
    ];

    return $this;
}
```

`AttachmentNormalizer` 只检查附件名，不检查 MIME：

```php
$attachments[] = [
    'data' => $data,
    'name' => $name !== '' ? $name : 'attachment.bin',
    'mime' => (string) ($item['mime'] ?? ''),
];
```

`fill()` 又把它直接交给 PHPMailer：

```php
$mailer->addStringAttachment($file['data'], $file['name'], PHPMailer::ENCODING_BASE64, $file['mime']);
```

PHPMailer `attachAll()` 用 `sprintf('Content-Type: %s; name=%s', $type, ...)` 直接拼接，没有对 `$type` 做 `secureHeader()`。

**复现**

```php
$message->attachData('DATA', 'a.txt', "text/plain\r\nX-Injected-Header: yes");
```

生成的 MIME body：

```
Content-Type: text/plain
X-Injected-Header: yes; name=a.txt
Content-Transfer-Encoding: base64
```

**影响**

- 注入任意 MIME 段头，例如 `Content-Disposition: inline`、`Content-Type: text/html`、`Content-ID`、`Content-Transfer-Encoding`；
- 可把附件伪装成正文内联 HTML，配合社会工程或邮件客户端解析差异进行钓鱼；
- 可制造畸形 MIME，导致部分客户端显示异常或把附件当正文；
- 如果 `mime` 来自上传文件的 Content-Type 等外部元数据，攻击者可直接控制。

**修复建议**

1. 在 `AttachmentNormalizer` 和/或 `MailMessage::attachData()` 入口校验 MIME：
   - 只允许 `type/subtype`，正则如 `^[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+$`；
   - 拒绝 CR、LF、NUL 及其他控制字符；
   - 拒绝分号、引号、反斜杠和参数，避免把 `charset` 等参数拼进 PHPMailer 的 `%s`。
2. 空 MIME 统一归一为 `application/octet-stream`。
3. 附件名、CID 也增加控制字符检查（当前只拒绝 CR/LF）。
4. 增加回归测试：合法 `text/plain`、`image/png` 通过；`text/plain\r\nX: y`、`text/plain; charset=utf-8`、空字节全部抛 `InvalidMailMessageException`。

---

### MAIL-03 日志异常改变发送结果并掩盖真实异常

**位置**

- `src/Mail/MailerManager.php:64-72`
- `src/Mail/MailerManager.php:100-109`

**代码现状**

```php
try {
    $result = $mailer->send($message);
    $this->log($name, $mailer->transport(), $message, $result, null);

    return $result;
} catch (MailTransportException $e) {
    $this->log($name, $mailer->transport(), $message, null, $e);
    throw $e;
}
```

`log()` 直接调用外部 PSR-3 logger，没有任何保护。

**复现**

使用一个 `info()` 抛 `RuntimeException('logger backend down')` 的 logger，并配置 `transport=log`：

```
RuntimeException: logger backend down
LogTransport recorded: 1 message(s)
```

即：邮件已经「发送」成功，但调用方收到异常。如果这是 SMTP，调用方按异常重试就会重复投递。

失败路径同样有问题：如果 `MailTransportException` 已经抛出，而 catch 块里的 `log()` 又抛异常，原始传输异常会被新异常覆盖，Job 看到的是 `RuntimeException`，无法按 `MailTransportException` 正确重试。

**影响**

- 日志后端故障变成业务发送失败；
- 重试造成重复发信；
- 原始传输错误被掩盖，排障和重试分类失效；
- 与 README「成功才返回 `MailResult`」的契约冲突。

**修复建议**

1. `log()` 内部用 `try { ... } catch (\Throwable) { /* 降级到 error_log 或静默 */ }`，日志永远不能改变发送结果。
2. 成功路径先拿到 `$result`，日志失败也返回 `$result`。
3. 失败路径先保存原异常，日志失败后继续抛出原异常（不要 `throw $e` 前被日志打断）。
4. 与 MAIL-16 一起改造：所有异常都记录，但记录动作本身必须尽力而为。
5. 回归测试：logger 抛异常时，成功仍返回 `MailResult`；传输失败仍抛原 `MailTransportException`。

---

### MAIL-04 非邮箱格式的 SMTP 用户名被拒绝

**位置**

- `src/Mail/Support/MailerConfigValidator.php:97-99`
- `src/Mail/Support/AddressGuard.php:15-24`

**代码现状**

```php
if ($auth) {
    AddressGuard::assert($username);
}
```

`AddressGuard::assert()` 要求 `PHPMailer::validateAddress($email)` 通过，即必须是 RFC 邮箱地址。

**复现**

```php
MailerConfigValidator::normalize('ses', [
    'transport' => 'smtp',
    'host' => 'email-smtp.us-east-1.amazonaws.com',
    'port' => 465,
    'encryption' => 'ssl',
    'auth' => true,
    'username' => 'AKIAIOSFODNN7EXAMPLE',
    'password' => 'secret',
    'from' => ['address' => 'noreply@example.com'],
]);
```

输出：

```
InvalidMailMessageException: Invalid mail address
```

**影响**

- AWS SES 的 SMTP 用户名是 Access Key ID（`AKIA...`），不是邮箱；
- SendGrid 的 SMTP 用户名通常是 `apikey`；
- 部分企业 Relay、自建 Postfix/Postal 也允许任意用户名；
- 这些服务全部无法通过当前配置校验；
- 异常类型是 `InvalidMailMessageException`，而 README 把「配置错误」归类为 `InvalidMailerException`，调用方按 `InvalidMailerException` 捕获会漏掉。

**修复建议**

1. SMTP `username` 只校验：非空、无 CR/LF/NUL、长度合理；不再强制 RFC 邮箱格式。
2. 对 `KnownProviders` 中确实要求「发件人 = 登录账号」的国内服务商，继续做 `from.address` 与 `username` 的相等比较；如需严格邮箱，只对这些 provider 单独校验。
3. 所有配置阶段抛出的地址校验异常统一包装成 `InvalidMailerException`，保持异常契约。
4. 回归测试：`AKIA...`、`apikey` 可通过；含 CR/LF 的用户名被拒；QQ/163 的 `from` 不匹配仍被拒。

### 4.1 P1 修复记录（2026-10-20）

| ID | 修复实现 | 变更文件 | 回归用例 |
|---|---|---|---|
| MAIL-01 | SMTP 发送时挂 `action_function`，统计 DATA 被接受后的成功回调。`send()` 抛异常且已有成功回调时抛 `PartialDeliveryException`（携带 `acceptedRecipients`）；否则仍抛可重试的 `MailTransportException`。发送结束后恢复原回调。Manager 将部分投递纳入失败日志，并记录 `accepted_recipients` | `src/Mail/PhpMailerMailer.php`、`src/Mail/MailerManager.php`、`src/Mail/Exception/PartialDeliveryException.php`（新增） | `testPartialDeliveryThrowsNonRetryableException`、`testAllRecipientsRejectedRemainsRetryableTransportError`、`testConnectionFailureBeforeDataRemainsRetryable`、`testPartialDeliveryIsLoggedWithAcceptedCount` |
| MAIL-02 | 新增 `AddressGuard::assertMimeType()`：只接受裸 `type/subtype`，拒绝参数、CR/LF、控制字符和超长值；空 MIME 保留为空，由 PHPMailer 推断。`attachData()` 入口和 `AttachmentNormalizer` 编译阶段各校验一次；MAIL-19 后附件数组已改为 private，只能经该方法进入 | `src/Mail/Support/AddressGuard.php`、`src/Mail/MailMessage.php`、`src/Mail/Support/AttachmentNormalizer.php` | `testAttachDataRejectsMimeHeaderInjection`、`testAttachDataRejectsMalformedMimeTypes`、`testValidMimeTypeIsAccepted` |
| MAIL-03 | `MailerManager::log()` 内捕获 `Throwable` 并静默降级。成功结果不受影响，失败路径保留原始 `MailTransportException` | `src/Mail/MailerManager.php` | `testLoggerFailureDoesNotChangeSuccessfulSend`、`testLoggerFailureDoesNotMaskTransportException` |
| MAIL-04 | `username` 不再走 RFC 邮箱校验，改为只拒绝控制字符；配置错误仍统一抛 `InvalidMailerException`。已知服务商的 `from.address` 与 `username` 匹配规则保持不变 | `src/Mail/Support/MailerConfigValidator.php` | `testSmtpUsernameDoesNotNeedToBeEmail`、`testSmtpUsernameRejectsControlCharacters` |

实现与审查建议的差异：

- MAIL-01 没有按 PHPMailer 异常文案或错误码判断，改用 `action_function`。回调只在 DATA 被接受后触发，能真实反映「已有收件人收到正文」，不依赖 `recipients_failed` 文案。`PartialDeliveryException` 只携带已接受数量，不保留被拒地址（PHPMailer 未公开 `$bad_rcpt`）。
- MAIL-02 空 MIME 没有强制归一为 `application/octet-stream`。空值不构成注入面，交给 PHPMailer 按其默认值处理，避免改变现有附件行为。

验证：

- 宿主机 PHP 8.4.14：`OK (34 tests, 79 assertions)`。
- 容器 `swoolefy-php85-v62`（PHP 8.5.8 / Swoole 6.2.2）：`OK (34 tests, 79 assertions)`。
- `src/Mail/README.md`、`docs/library-6.x-Mail邮件技术方案.md` 已同步异常分类、重试语义、MIME 校验和用户名规则。

---

## 5. P2 / 急需优化项

### MAIL-05 SMTP 加密方式与端口被硬绑定

**位置**：`src/Mail/Support/MailerConfigValidator.php:72-85`、`src/Mail/Transport/SmtpTransport.php:35-41`

当前只接受三组精确组合：

| encryption | 允许端口 |
|---|---|
| `ssl` | 465 |
| `tls` | 587 |
| `''` | 25 |

其余组合直接抛 `InvalidMailerException`。已复现被拒的场景：

- MailHog / Mailpit 默认 `1025` 明文；
- 企业 Relay 的 `2525 + STARTTLS`；
- `25 + STARTTLS`（很多内网 MTA）；
- `587 + 隐式 TLS`（部分服务商支持）；
- 非标准端口上的 `ssl` / `tls`。

设计与 README 都明确写了这组硬绑定，但技术方案的测试计划又建议用 MailHog 做 SMTP 冒烟（`docs/library-6.x-Mail邮件技术方案.md:521`），默认 1025 端口无法通过校验，文档自相矛盾。

**建议**：把端口与加密解耦，仅保留「钉钉必须 465/ssl」这类 provider 规则；加密值支持 `ssl`、`tls`、`''`，并允许任意端口。`SmtpTransport` 的 `SMTPAutoTLS` 也应显式可配（当前 `tls` 才开启，明文/ssl 关闭）。补 1025、2525、25+STARTTLS、587+ssl 的配置测试。

---

### MAIL-06 仅 CC/BCC 的邮件被拒绝

**位置**：`src/Mail/Support/MessageCompiler.php:30-32`

```php
if ($message->to === []) {
    throw new InvalidMailMessageException('Mail message requires at least one recipient');
}
```

只检查 `to`，没有检查 `cc`/`bcc`。PHPMailer 自身在 `preSend()` 中用 `count($to)+count($cc)+count($bcc) < 1` 判断（vendor `:1739`），并支持只有 BCC 的邮件（会生成 `To: undisclosed-recipients:;`，vendor `:1800`）。

**影响**：BCC 群发、合规抄送、只密送领导的场景无法使用；对使用 `cc()` 的调用方是行为倒退。

**建议**：改为 `count($message->to) + count($message->cc) + count($message->bcc) === 0` 才拒绝；补 CC-only、BCC-only 测试，并更新 README 的「无收件人」描述。

---

### MAIL-07 `auth => 'false'` 被转为 true

**位置**：`src/Mail/Support/MailerConfigValidator.php:91`

```php
$auth = (bool) ($config['auth'] ?? true);
```

PHP 中 `(bool) 'false'` 是 `true`，`(bool) 'no'` 也是 `true`。从 env/YAML/INI 读取配置时很常见。

**复现**：`'auth' => 'false'` 且不配账号密码，抛：

```
InvalidMailerException: Mailer [relay] requires username and password
```

**建议**：用显式布尔解析（`filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)` 或自定义 `'1/true/yes/on'`、`'0/false/no/off'`），无法识别时抛 `InvalidMailerException`，不要静默转 bool。补字符串布尔测试。

---

### MAIL-08 `$mailer->send()` 返回值被忽略

**位置**：`src/Mail/PhpMailerMailer.php:49-58`

```php
$mailer->send();
...
return new MailResult($messageId !== '' ? $messageId : $mailer->MessageID, $this->transport());
```

生产路径 `new PHPMailer(true)` 是异常模式，理论上是「true 或抛异常」；但自定义 `phpMailerFactory`、PHPMailer 子类或未来关闭异常模式时，`send()` 可能返回 `false`（vendor `:1663-1679`）。替身返回 `false` 时模块仍返回：

```
MailResult messageId=[], transport=smtp
```

**建议**：`if ($mailer->send() !== true) { throw new MailTransportException('Mailer returned false without exception'); }`；同时把空 Message-ID 视为可接受的正常情况（部分本地 MTA 不生成），不要因此误判失败。

---

### MAIL-09 发送前配置在 `try` 外，PHPMailer 异常泄漏

**位置**：`src/Mail/PhpMailerMailer.php:45-54`

```php
$this->transport->apply($mailer);
$this->applyDkim($mailer);
$this->fill($mailer, $prepared);

try {
    $mailer->send();
} catch (\PHPMailer\PHPMailer\Exception $e) {
    ...
}
```

`fill()` 中的 `setFrom()`、`addAddress()`、`addAttachment()`、`addEmbeddedImage()` 都可能抛 `PHPMailer\PHPMailer\Exception`。例如附件在 `normalize()` 之后、`fill()` 之前被删除，或自定义 PHPMailer 子类在 `setFrom()` 中抛错。

**复现**：子类 `setFrom()` 抛 `PHPMailer\PHPMailer\Exception`，模块直接泄漏原始异常，调用方 `catch (MailException)` 捕不到，也没有经过 `ErrorRedactor`。

**建议**：把 `transport->apply()`、`applyDkim()`、`fill()` 全部移入 `try`；捕获 PHPMailer 异常后统一包装、脱敏。对于 `TypeError` 等编程错误是否包装可单独评估，但至少 PHPMailer 的异常必须遵守 `MailException` 契约。

---

### MAIL-10 DKIM 部分配置被静默忽略

**位置**：`src/Mail/Support/MailerConfigValidator.php:137-161`；PHPMailer `:1810-1815`

当前逻辑：

- `dkim` 为数组但 `domain` 为空 → 直接 `return null`，即使配了 `private`/`selector`；
- `selector` 允许为空字符串；
- 私钥只检查 `is_file()`，不检查 `is_readable()`；
- 没有校验私钥内容能否被 `openssl_pkey_get_private()` 解析。

PHPMailer 只在 `DKIM_domain` 和 `DKIM_selector` 都非空且私钥存在时才签名；否则静默跳过。已复现 `selector: ''` 被接受，最终因 `DKIM_selector` 为空而未签名；`selector: []` 还会触发 `Array to string conversion` warning，并被归一为字符串 `Array`。

**影响**：以为已开启 DKIM，实际邮件未签名，影响送达率和反垃圾评分；私钥不可读时可能在邮件里生成空的 `b=` 签名头，形成无效签名。

**建议**：只要 `dkim` 数组存在，就要求 `domain`、`selector`、`private` 都有值；`selector` 用 `[A-Za-z0-9._-]+` 校验；私钥要求 `is_file() && is_readable()`，并可选在配置阶段用 `openssl_pkey_get_private()` 试读；失败抛 `InvalidMailerException`。补部分配置、空 selector、不可读私钥测试。

---

### MAIL-11 sendmail 路径含空格被静默截断

**位置**：`src/Mail/Support/MailerConfigValidator.php:123-131`；`tests/Mail/MailModuleTest.php:110-119`

```php
$binary = preg_split('/\s+/', $path)[0] ?? '';
```

`/opt/send mail/sendmail -bs` 会被静默变成 `/opt/send`；现有单测 `testSendmailPathDropsFlags()` 还把「丢弃 `-bs`」固化为预期行为。

**影响**：路径含空格时执行错误的二进制或直接失败，错误信息不指向配置；参数被静默丢弃，运维难以发现。

**建议**：要求绝对路径；路径中禁止空白（PHPMailer 用 `escapeshellcmd()` 包整段路径，本来就不能带参数）；检查 `is_executable()`（注意宿主机与容器路径差异）；错误信息包含原始配置值；更新现有测试为「含空格直接拒绝」，而不是静默截断。

---

### MAIL-12 `Address` 未 trim，前后空格导致校验/匹配不一致

**位置**：`src/Mail/Address.php:12-17`、`src/Mail/Support/MailerConfigValidator.php:101-104`

`AddressGuard::assert()` 内部 `trim($email)` 后校验，但 `Address` 保存的是原始字符串：

```php
public function __construct(
    public readonly string $email,
    public readonly string $name = '',
) {
    Support\AddressGuard::assert($this->email, $this->name);
}
```

**复现**：

- `new Address(' user@example.com ')` 成功，`email` 仍带空格；
- 已知服务商配置 `from.address = ' user@example.com '` 时，`strcasecmp($from->email, $username)` 不相等，抛 `InvalidMailerException: from.address must match username`；
- 去重时 `'a@x.com'` 与 `' a@x.com '` 被视为不同地址。

**建议**：在 `Address` 构造时 `trim()` 邮箱（显示名可保留首尾空格或一并 trim），再校验和保存；`MailerConfigValidator::address()` 也使用归一化后的值；补空格/大小写去重测试。

---

### MAIL-13 未配置 `view_path` 时回退到当前工作目录

**位置**：`src/Mail/Config/MailConfig.php:76-84`、`src/Mail/Template/TemplateRenderer.php:26-29`

`MailConfig::templatePath()` 返回空字符串；`TemplateRenderer` 中：

```php
$root = realpath($this->viewPath);
$file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $name . '.html');
```

PHP 的 `realpath('')` 返回当前工作目录。已复现：未配置 `view_path` 时，`TemplateRenderer('')` 成功渲染了 `/tmp/mail-template-cwd/welcome.html`。

**影响**：模板配置缺失时不会报错，而是从进程 cwd 读取同名 `.html`；可能渲染错误内容，或在 cwd 存在敏感 HTML 时泄露内容；部署环境不同表现不同。

**建议**：`TemplateRenderer` 构造函数中要求 `viewPath` 非空且是目录，并缓存 `realpath`；`MailFactory` 在创建模板引擎时校验；只有真正使用模板时才要求配置，纯文本邮件不应被强制。补空路径、非目录、不存在目录测试。

---

### MAIL-14 附件大小统计可被 stat 为 0 的文件绕过

**位置**：`src/Mail/Support/AttachmentNormalizer.php:41`、`:57`

```php
$total += filesize($path) ?: 0;
```

`filesize()` 返回 `0` 或 `false` 时都按 0 处理。容器内实测 `/proc/self/environ`：

- `is_file()` 为 true；
- `is_readable()` 为 true；
- `filesize()` 为 0；
- `file_get_contents()` 实际读出 179 字节；
- `max_bytes=1` 时仍被接受。

此外存在 TOCTOU：统计后文件可能增长；技术方案 §9 第 6 条（`docs/library-6.x-Mail邮件技术方案.md:492`）声称按「读入字节数」合计，但实现只做了 stat。

**影响**：附件上限可被特殊文件绕过；大文件在统计后增长会突破上限；内存/邮件体积不可控。

**建议**：

1. `filesize()` 返回 `false` 时直接抛 `InvalidMailMessageException`，不要回落 0。
2. 对路径附件，在归一化阶段按 `remaining + 1` 读取实际内容并计数（或使用受限流），超限立即拒绝；之后以 `data` 形式交给 PHPMailer，避免二次读取和 TOCTOU。
3. 拒绝非普通文件（procfs、设备文件等）；至少对 `stat` 的 mode 做常规文件判断。
4. 注意内存权衡：默认上限 20MB，读入内存可接受；若要支持更大附件，改用临时流并明确生命周期。
5. 补容器内特殊文件测试与「统计后文件增长」模拟测试。

---

### MAIL-15 长度小于 4 的密钥不脱敏

**位置**：`src/Mail/Support/ErrorRedactor.php:16-26`

```php
if (strlen($secret) < 4) {
    continue;
}
```

3 位密码 `abc` 会原样留在异常消息里（已用替身复现）。

**建议**：密码和 DKIM passphrase 无论长度都必须替换；用户名可按长度跳过（或仅当包含 `@` 时替换），避免把常见短单词全部打成 `***`。替换前按长度降序排序，避免短密钥先替换后破坏长密钥匹配。补 1/3 字符密码测试。

---

### MAIL-16 日志覆盖不足

**位置**：`src/Mail/MailerManager.php:61-72`、`:80-110`

当前只有成功和 `MailTransportException` 会写日志：

- `mailer()` 解析失败（`InvalidMailerException`）发生在 `try` 之前，不记录；
- `InvalidMailMessageException`、`TemplateNotFoundException`、`InvalidMailerException` 不记录；
- 传输失败用 `info` 级别，不适合告警；
- 没有耗时、`from`（脱敏）、模板名、重试次数、业务追踪 ID；
- 日志数组包含全部 to/cc（虽已脱敏），大量收件人时体积不可控。

**建议**：把整个 `send()`（含 mailer 解析）纳入统一 try/catch/finally；成功 `info`、传输失败 `warning/error`、配置/消息错误 `warning`；字段增加 `duration_ms`、`from`（脱敏）、`template`、`recipient_count`；to/cc 只保留前 N 个加总数；日志动作本身必须捕获异常（MAIL-03）。补「所有异常路径都有日志」测试。

---

### MAIL-17 配置数值静默截断

**位置**：`src/Mail/Support/MailerConfigValidator.php:66`、`:106`、`src/Mail/Config/MailConfig.php:99-108`

```php
$port = (int) ($config['port'] ?? 0);
$timeout = (int) ($config['timeout'] ?? 10);
$max = (int) ($attachments['max_bytes'] ?? 20 * 1024 * 1024);
```

已复现：

- `max_bytes => '20MB'` → `20`，几乎所有附件都被拒；
- `timeout => '10s'` → `10`；
- `port => '465abc'` → `465`；
- `max_bytes => '0.5'` → `0`，抛「至少 1 字节」。

**建议**：严格校验整数（`is_int` 或 `filter_var(FILTER_VALIDATE_INT)`），拒绝非数字字符串；如果产品需要 `20MB` 这种写法，显式解析单位并做溢出检查；错误信息包含原始值。补字符串、浮点、单位测试。

---

### MAIL-18 `extend()` 大小写与自定义创建器绕过校验

**位置**：`src/Mail/MailFactory.php:37-40`、`:45-52`

```php
public function extend(string $transport, callable $creator): void
{
    $this->customCreators[$transport] = $creator;
}
...
$transport = strtolower(trim((string) ($config['transport'] ?? '')));
if (isset($this->customCreators[$transport])) {
    return ($this->customCreators[$transport])($config, $name);
}
$normalized = MailerConfigValidator::normalize($name, $config);
```

- `extend('SMTP', ...)` 存入 `SMTP`，查找时用 `smtp`，永远匹配不到；
- 自定义创建器在 `normalize()` 之前执行，完全绕过 host/port/from/auth/DKIM/加密校验；
- 现有单测正是通过 `extend('smtp', ...)` 注入替身，依赖了「绕过校验」这一行为。

**建议**：`extend()` 对 key 做 `strtolower(trim())`；明确自定义创建器的契约——要么要求调用方自行校验，要么提供 `normalizeCommon()` 并在文档中说明；为自定义传输增加配置校验测试。

---

### MAIL-19 `MailMessage` 公共可变数组破坏不变量

**位置**：`src/Mail/MailMessage.php:13-43`

`$to`、`$cc`、`$bcc`、`$attachments`、`$embeds`、`$templateVars` 都是 public 数组。调用方可以：

- 直接 `$message->to[] = 'not-an-address'`，绕过 `Address` 构造校验；
- 直接塞入含 CR/LF 的 `mime`，绕过 `attachData()`（MAIL-02）；
- 塞入非数组的附件项，`AttachmentNormalizer` 访问 `$item['name']` 时产生 warning/TypeError，逃逸为 `Error` 而不是 `MailException`；
- 直接修改 `templateVars` 为非标量，模板渲染阶段才报错。

**建议**：改为 private + 只读访问器/`withX()`；或至少在 `MessageCompiler` 入口对每个元素做类型和形状校验，并把 `TypeError` 包装成 `InvalidMailMessageException`。考虑项目已明确「不做旧配置兼容」，优先采用 private 方案。

---

### MAIL-20 sendmail / mail 传输在协程中可能阻塞

**位置**：`src/Mail/Transport/SendmailTransport.php:23-27`、`src/Mail/Transport/MailTransport.php:22-25`、PHPMailer `:894-897`、`:1941-1969`

- `mail` 传输最终调用 PHP `mail()`（vendor `:894-897`），这是 C 层系统调用，Swoole 的 stream/SSL hook 不覆盖，必然阻塞当前 Worker；
- `sendmail` 传输通过 `popen()` 启动子进程（vendor `:1941-1969`），是否让出协程取决于进程 hook 配置，不能依赖默认 TCP hook；
- README 只在 SMTP 段落提示 hook（`src/Mail/README.md:229`），对 `mail`/`sendmail` 没有警告。

**影响**：在只启用 TCP hook、未启用进程 hook 的配置下，`mail()` 必然阻塞，`sendmail` 的子进程等待也可能阻塞整个 Worker 的所有协程；高并发下表现为周期性卡顿。

**建议**：文档明确「协程环境优先 SMTP；`mail`/`sendmail` 只用于 CLI/低并发」；在 `MailTransport`/`SendmailTransport` 中检测 `Swoole\Coroutine::getCid() >= 0` 并发出一次性 warning（或直接禁止）；需要异步时交给框架 Job/任务进程。补文档和运行时告警，不做虚假的协程化承诺。

---

### MAIL-21 `default_mailer` 静默取第一个键

**位置**：`src/Mail/Config/MailConfig.php:33-46`

设计文档（`:251`）与 README（`:29`）都明确「未配置时用第一个 mailer」。这在单账号时方便，但多账号配置下：

- 新增一个 mailer 并放在数组前面，会静默改变默认发件账号；
- 不同环境合并配置时顺序不稳定；
- 可能从错误账号发出，触发合规或品牌问题。

**建议**：生产环境要求显式 `default_mailer`；保留 fallback 时至少 `warning` 一次（可通过 PSR-3 logger）；文档补充「生产必须显式配置」。这属于设计取舍，不建议直接移除 fallback。

---

### MAIL-22 收件人数量无上限，去重 O(n²)

**位置**：`src/Mail/Support/MessageCompiler.php:28-32`、`src/Mail/MailMessage.php:153-166`

- 没有 `max_recipients` 配置或硬上限；
- `addRecipient()` 每加一个地址都遍历 to/cc/bcc 三个列表，n 个收件人是 O(n²)；
- 大量 BCC 会放大 SMTP 事务、内存、日志上下文（MAIL-16）和发送时间；
- 只有 BCC 数量被记录，没有上限保护。

**建议**：增加 `max_recipients`（如默认 100，可配置）并在 `MessageCompiler` 校验 to+cc+bcc 总数；去重改用 `array<string,true>` 集合；日志只保留前 N 个地址和总数。补超限测试。

---

### MAIL-23 `log` 传输在生产误配时静默吞信

**位置**：`src/Mail/Transport/LogTransport.php:32-58`

`LogTransport::send()` 只把信封放进内存数组，然后返回 `new MailResult('log-' . bin2hex(random_bytes(8)), 'log')`，表现为发送成功。没有环境判断，也没有 warning。

**影响**：生产配置误写 `transport: log`（或复制了开发配置）时，所有邮件静默不发出，监控上仍是成功；这比抛异常更难发现。

**建议**：增加显式开关（如 `mail.log_transport_allowed`，默认仅 `APP_ENV=local/testing` 开启），生产配置直接拒绝或至少 `warning`；文档强调 `log` 只用于联调/测试。另外 `sent()` 目前保留明文主题，若调试接口会暴露，建议脱敏或截断。

---

### MAIL-24 模板渲染健壮性与缓存

**位置**：`src/Mail/Template/TemplateRenderer.php:26-53`

- `file_get_contents()` 失败时只抛 `TemplateNotFoundException`，但 race/权限变化时可能先产生 warning；
- `preg_replace_callback()` 在 PCRE 回溯超限等情况下返回 `null`，当前用 `$rendered ?? $html` 静默返回未渲染的原始模板（占位符原样发出）；
- 没有检查 `preg_last_error()`；
- 每次 `render()` 都 `realpath()` + 读文件，高并发发信时重复磁盘 I/O；
- 模板变量只支持标量（合理），但没有模板编译缓存。

**建议**：构造函数中解析并缓存根目录；读取失败/不可读统一抛 `TemplateNotFoundException`；`$rendered === null` 时检查 `preg_last_error()` 并抛 `InvalidMailMessageException`，不要静默回退；增加按 `path + mtime` 的进程内有界缓存（注意配置热更新时失效）；补不可读文件、超大模板、缓存失效测试。

### 5.1 P2 修复记录（2026-10-20）

| ID | 修复实现 | 变更文件 | 回归用例 |
|---|---|---|---|
| MAIL-05 | 端口仅校验 1..65535，`encryption` 仅接受 `ssl` / `tls` / 空字符串，二者不再互相绑定；新增 `autotls`（未配置时 `tls` 为 true，其余为 false），由 `SmtpTransport` 写入 `SMTPAutoTLS`。钉钉仍强制 465 + ssl | `MailerConfigValidator.php`、`SmtpTransport.php` | `testSmtpPortAndEncryptionAreIndependent`、`testAutotlsCanBeDisabledExplicitly` |
| MAIL-06 | 收件人校验改为只判断 to+cc+bcc 总数为 0；仅 cc / 仅 bcc 交由 PHPMailer 生成 undisclosed-recipients | `MessageCompiler.php` | `testCcOnlyAndBccOnlyMessagesAreAccepted` |
| MAIL-07 | 新增 `ConfigValue::boolean()`：接受布尔、0/1 与 `filter_var` 可识别的字符串，其余抛 `InvalidMailerException`；`auth` / `autotls` 统一走该入口 | `Support/ConfigValue.php`（新增）、`MailerConfigValidator.php` | `testBooleanConfigAcceptsExplicitStringForms` |
| MAIL-08 | `send()` 返回非 `true` 时读取 `ErrorInfo`（脱敏、空值兜底），按已接受收件人数抛 `PartialDeliveryException` 或 `MailTransportException`，不再静默成功 | `PhpMailerMailer.php` | `testSendReturningFalseBecomesTransportException` |
| MAIL-09 | PHPMailer 装配段（地址、主题、附件、内嵌图、DKIM）整体包 try，PHPMailer 异常转为 `InvalidMailMessageException` 并脱敏，重试不会成功 | `PhpMailerMailer.php` | `testFillStagePhpMailerExceptionBecomesInvalidMessage` |
| MAIL-10 | 出现 `dkim` 即要求 domain、合法 selector（`[A-Za-z0-9._-]+`）和可读私钥；openssl 可用时先解析私钥，失败直接拒绝，不再静默跳过 | `MailerConfigValidator.php` | `testDkimRequiresCompleteAndReadableKey`、`testDkimInvalidPemIsRejected` |
| MAIL-11 | sendmail `path` 必须是绝对路径、可执行的普通文件，拒绝空白、`..` 与 NUL，不再截断参数 | `MailerConfigValidator.php` | `testSendmailPathMustBePlainAbsoluteExecutable` |
| MAIL-12 | `Address` 构造时 trim 地址，to/cc/bcc/from/reply_to 全部经同一入口；去重与服务商 `from` 匹配使用 trim 后的值 | `Address.php`、`MailMessage.php` | `testAddressesAreTrimmedBeforeDedupAndProviderCheck` |
| MAIL-13 | 模板根目录延迟解析并缓存；空值、不存在、非目录、realpath 失败在 `render()` 时抛 `TemplateNotFoundException`，不再回退 cwd | `Template/TemplateRenderer.php` | `testEmptyOrInvalidTemplateRootNeverFallsBackToCwd` |
| MAIL-14 | 附件/内嵌图在编译阶段按实际读取字节计数，多读 1 字节判断超限；数据快照进内存后交给 PHPMailer，stat 为 0 或文件被替换都绕不过限额 | `Support/AttachmentNormalizer.php`、`PhpMailerMailer.php` | `testAttachmentLimitUsesActualReadBytes`、`testAttachmentBytesAreSnapshottedBeforeSending`、`testProcStatZeroFileCannotBypassLimit` |
| MAIL-15 | 脱敏按密钥长度降序替换，所有非空密码 / passphrase 无论长短都替换；调用方过滤长度小于 4 的用户名，避免把常见子串打码 | `Support/ErrorRedactor.php`、`PhpMailerMailer.php` | `testShortPasswordsAreAlwaysRedacted` |
| MAIL-16 | `send()` 从 mailer 解析开始整体纳入 try/catch，成功 `info`、传输/部分投递 `error`、其余 `warning`；字段增加 `from`、`source`、`template`、`duration_ms`、`bcc_count` 等，to/cc 只保留前 10 个 | `MailerManager.php` | `testAllFailurePathsAreLoggedWithLevelsAndFields`、`testLogContextCapsRecipientLists` |
| MAIL-17 | 新增 `ConfigValue::integer()`：只接受 int 或纯整数字符串，`'20MB'`、`'10s'`、`'465abc'`、`'0.5'` 全部拒绝；port / timeout / max_bytes / max_recipients 均做范围校验 | `Support/ConfigValue.php`（新增）、`MailerConfigValidator.php`、`Config/MailConfig.php` | `testNumericConfigStringsAreStrict` |
| MAIL-18 | `extend()` 对 transport 做 `strtolower(trim())`；自定义创建器先走 `normalizeCommon()`（from / reply_to / DKIM / transport），内置传输仍走完整 `normalize()`；文档写明自定义创建器需自行校验传输专属字段 | `MailFactory.php`、`MailerConfigValidator.php` | `testExtendIsCaseInsensitiveAndStillValidatesCommonConfig` |
| MAIL-19 | `to/cc/bcc/attachments/embeds/templateVars` 改为 private，提供 `toList()` / `ccList()` / `bccList()` / `attachmentList()` / `embedList()` / `templateVariables()` 只读访问器；附件项形状由内部方法保证 | `MailMessage.php`、`MessageCompiler.php`、`AttachmentNormalizer.php` | `testMessageCollectionsArePrivateAndExposeLists` |
| MAIL-20 | 新增 `BlockingTransportWarning`，协程内使用 `mail` / `sendmail` 时每进程每传输 `error_log` 一次；README 与技术方案写明优先 SMTP 或移入任务进程 | `Support/BlockingTransportWarning.php`（新增）、`Transport/MailTransport.php`、`Transport/SendmailTransport.php` | `testMailAndSendmailWarningsAreEmittedOnceInsideCoroutine` |
| MAIL-21 | 多个 mailer 时必须显式 `default_mailer`，否则抛 `InvalidMailerException`；只有一个 mailer 时仍允许省略；配置了不存在的名字同样拒绝 | `Config/MailConfig.php` | `testDefaultMailerMustBeExplicitWithMultipleMailers`、`testConfiguredDefaultMailerMustExist` |
| MAIL-22 | 新增 `max_recipients`（默认 100，范围 1..10000），`MessageCompiler` 按 to+cc+bcc 总数校验；去重改为 `array<string,true>` 集合，O(n)；日志只保留前 10 个地址 | `MailMessage.php`、`MessageCompiler.php`、`Config/MailConfig.php`、`MailerManager.php` | `testRecipientLimitAndStrictMaxConfig`、`testLogContextCapsRecipientLists` |
| MAIL-23 | `log` 传输首次使用时 `error_log` 告警「不投递」；`sent()` 只保留主题前 2 字符 + `***` 和原长度，不再暴露明文主题 | `Transport/LogTransport.php` | `testLogTransportWarnsOnceAndMasksSubject` |
| MAIL-24 | 读取失败统一抛 `TemplateNotFoundException`（`@` 抑制 warning）；PCRE 返回 null 时按 `preg_last_error_msg()` 抛 `InvalidMailMessageException`；按 `path + mtime + size + inode` 维护最多 128 条的进程内缓存，文件变化自动失效 | `Template/TemplateRenderer.php` | `testTemplateReadErrorsAndPcreFailuresThrow`、`testTemplateCacheRefreshesWhenFileChanges` |

实现与审查建议的差异：

- MAIL-13 把根目录解析放在首次 `render()` 而不是构造函数：纯文本配置不需要模板目录，构造阶段不应因未配置模板而失败。
- MAIL-18 的自定义创建器仍无法覆盖传输专属字段（host/port 等），`normalizeCommon()` 只保证 from/reply_to/DKIM 与 transport 名称合法；这一契约已写入 README，自定义传输需在创建器内自行校验其余字段。
- MAIL-21 未采用「warning + fallback」：按项目「不做旧配置兼容」的约定，多 mailer 时直接要求显式配置，避免静默换发件账号。
- MAIL-23 未引入 `APP_ENV` 开关：组件不感知运行环境，改为首次使用告警 + 主题脱敏，避免依赖框架环境常量。
- MAIL-24 没有做模板编译缓存（模板语法只有两次正则替换），只缓存文件内容；缓存上限 128 条，超限时移除最早写入项。

验证：

- 宿主机 PHP 8.4.14：`OK (60 tests, 186 assertions, 1 skipped)`；跳过项为 macOS 无 procfs 的 `testProcStatZeroFileCannotBypassLimit`。
- 容器 `swoolefy-php85-v62`（PHP 8.5.8 / Swoole 6.2.2）：`OK (60 tests, 187 assertions)`，procfs 用例同时通过。
- `src/Mail/README.md`、`docs/library-6.x-Mail邮件技术方案.md` 已同步端口/加密、`autotls`、`max_recipients`、DKIM、sendmail、模板缓存与日志字段。

---

## 6. 已确认无问题 / 设计取舍

以下行为经代码或实测确认，当前实现是安全的，修复 P1/P2 时不要回退：

1. **模板路径穿越被阻断**：模板名白名单 `^[A-Za-z0-9_-]+$`，`realpath` 后再做根目录前缀判断；`../`、斜杠、PHP 文件均不可达。
2. **地址/主题/附件名 CR/LF 注入被拒绝**：`AddressGuard` 与 `MessageCompiler` 覆盖 to/cc/bcc/from/reply_to/subject，附件名也做了 CR/LF 检查。
3. **附件 `..`、目录和不可读文件被拒绝**：`AttachmentNormalizer::readableFile()` 拒绝路径中的 `..`，要求 `realpath` 后是 `is_file()` 且可读。
4. **SMTP 调试输出关闭**：`SMTPDebug = 0`，避免 AUTH 对话进入日志；每次 send 新建 PHPMailer，`SMTPKeepAlive = false`，不跨协程复用连接。
5. **传输异常不保留 PHPMailer 原始异常链**：避免 `ErrorInfo` 中的口令残留在 `previous`；密码与 DKIM passphrase 无论长短都会替换为 `***`；长度 ≥ 4 的用户名也会替换，更短的用户名因容易误伤普通文本不参与替换。
6. **已知服务商 `from` 与 `username` 匹配**：钉钉、QQ、腾讯企业邮、网易、飞书在配置阶段校验，避免服务器直接拒信。
7. **HTML 模板默认转义**：`{{ var }}` 做 `htmlspecialchars`，`{{{ var }}}` 才原样输出，语义清晰。
8. **收件人去重**：to/cc/bcc 之间按邮箱大小写不敏感去重；顺序稳定。
9. **失败只走异常**：没有 `ok=false` 分支，调用方只需处理 `MailException` 子类；未知 transport 被归类为不可重试的配置错误。
10. **配置外层 `mail` 包裹兼容**：`MailConfig::fromArray()` 支持外层再包一层 `mail`，与框架配置习惯一致。

---

## 7. 修复路线建议

### 7.1 发布前必须完成（P1，已完成）

| 顺序 | ID | 动作 | 预计影响文件 | 状态 |
|---|---|---|---|---|
| 1 | MAIL-01 | 新增 `PartialDeliveryException`，区分「部分已投递」；更新重试文档与测试 | `PhpMailerMailer.php`、`Exception/`、`MailerManager.php`、README/设计文档、`tests/Mail` | 已完成 |
| 2 | MAIL-02 | 增加 MIME 类型白名单校验，拒绝 CR/LF/参数/控制字符 | `MailMessage.php`、`AttachmentNormalizer.php`、`AddressGuard.php`、测试 | 已完成 |
| 3 | MAIL-03 | 日志调用捕获 `Throwable`，保证不改变发送结果、不覆盖原异常 | `MailerManager.php`、测试 | 已完成 |
| 4 | MAIL-04 | SMTP 用户名只校验非空/无换行；配置异常统一为 `InvalidMailerException` | `MailerConfigValidator.php`、测试 | 已完成 |

### 7.2 同版本建议完成（安全/正确性 P2，已完成）

MAIL-09、MAIL-10、MAIL-12、MAIL-13、MAIL-14、MAIL-15、MAIL-16、MAIL-17、MAIL-18、MAIL-19。

理由：这些项要么影响异常契约/安全边界，要么会在配置边界产生静默错误；修复成本低，回归测试容易补。10 项均已修复，实现与用例见 §5.1。

### 7.3 下一迭代（可用性/运维 P2，已完成）

MAIL-05、MAIL-06、MAIL-07、MAIL-08、MAIL-11、MAIL-20、MAIL-21、MAIL-22、MAIL-23、MAIL-24。

理由：多数是能力扩展或运维防护，需要同步更新 README/技术方案；其中 MAIL-05 与 MAIL-20 直接影响联调和 Swoole 生产稳定性。10 项已随本版本一并修复，未留到下一迭代，实现与用例见 §5.1。

---

## 8. 回归测试清单

### 8.1 基线

- [x] `./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Mail`：`OK (60 tests, 186 assertions, 1 skipped)`（审查基线为 `22 tests / 64 assertions`；跳过项为 macOS 无 procfs 的限额用例）。
- [x] 容器 `swoolefy-php85-v62`（PHP 8.5.8 / Swoole 6.2.2）内执行同一命令通过：`OK (60 tests, 187 assertions)`，procfs 用例同时通过。

### 8.2 P1 回归（已完成）

- [x] MAIL-01：替身 PHPMailer 模拟「一个好地址成功回调 + 一个 550」；断言抛 `PartialDeliveryException`，`acceptedRecipients === 1`，且不继承 `MailTransportException`。全部被拒和 DATA 前连接失败仍抛可重试的 `MailTransportException`；Manager 失败日志包含异常类和已接受数量。
- [x] MAIL-02：`mime` 含 `\r\n`、`;`、`\0`、逗号和超长值均抛 `InvalidMailMessageException`；合法 `text/plain` 通过；附件项只能经 `attachData()` / `attach()` 进入，编译阶段再校验一次。
- [x] MAIL-03：logger 在成功路径抛异常，`send()` 仍返回 `MailResult`；logger 在失败路径抛异常，仍抛原 `MailTransportException` 且消息不变。
- [x] MAIL-04：SES 风格 `AKIA...` 通过；含 `\n` 的用户名被拒；已知服务商的 `from` 匹配逻辑未改动。

### 8.3 P2 回归（已完成）

- [x] MAIL-05：1025 明文、2525 STARTTLS、25 STARTTLS、587 隐式 TLS 可配置并通过 `SmtpTransport::apply()` 断言；`autotls` 可显式关闭。
- [x] MAIL-06：仅 `cc()`、仅 `bcc()` 均可发送；三者都为空才抛异常。
- [x] MAIL-07：`'false'`、`'0'`、`'no'`、`'off'` 解析为 false；`'true'`、`'1'` 解析为 true；非法字符串抛配置异常。
- [x] MAIL-08：替身 `send()` 返回 `false` 时抛 `MailTransportException`，错误信息已脱敏。
- [x] MAIL-09：`fill()` 阶段抛 PHPMailer 异常时，调用方捕获到 `InvalidMailMessageException`，消息中无密码。
- [x] MAIL-10：DKIM 只配 domain、只配 selector、空 selector、不可读私钥、非法 PEM 均抛 `InvalidMailerException`；完整配置可签名。
- [x] MAIL-11：路径含空格、相对路径、不存在路径均被拒绝；正常绝对可执行文件通过。
- [x] MAIL-12：`' user@example.com '` 归一化为 `user@example.com`；已知服务商匹配成功；去重生效。
- [x] MAIL-13：空 `view_path`、非目录、不存在目录在渲染时抛 `TemplateNotFoundException`；不会从 cwd 读取。
- [x] MAIL-14：`max_bytes=1` 时 procfs 0 字节文件在容器内被拒；普通大文件按实际读取字节拒绝；发送前替换文件不影响已快照的附件。
- [x] MAIL-15：1/3 字符密码在异常消息中也被替换为 `***`；空密钥不产生空替换；短用户名不参与替换。
- [x] MAIL-16：配置异常、模板异常、传输异常、部分投递、成功五条路径都产生日志；日志中无密码/正文/BCC 全量；to/cc 截断为 10 条。
- [x] MAIL-17：`'20MB'`、`'10s'`、`'465abc'`、`'0.5'` 均拒绝；不再静默截断。
- [x] MAIL-18：`extend('SMTP')` 生效；自定义创建器仍经 `normalizeCommon()` 校验 from/reply_to/DKIM，契约已写入文档。
- [x] MAIL-19：`to/cc/bcc/attachments/embeds/templateVars` 均为 private，只能通过访问器读取；非法结构无法注入。
- [x] MAIL-20：协程内首次使用 `mail`/`sendmail` 输出一次性告警；README/技术方案已说明阻塞风险。
- [x] MAIL-21：多个 mailer 未配置 `default_mailer` 时抛 `InvalidMailerException`；显式配置优先；单 mailer 可省略。
- [x] MAIL-22：超过 `max_recipients` 抛 `InvalidMailMessageException`；`max_recipients` 非整数或越界抛配置异常；去重结果正确。
- [x] MAIL-23：`log` 传输首次使用告警一次；`sent()` 只保留脱敏主题和长度，不再暴露明文。
- [x] MAIL-24：不可读模板、PCRE 失败抛异常而非原样返回；模板缓存命中与 mtime/size/inode 变化后的失效正确。

### 8.4 手工/集成（未执行，需外部环境）

- [ ] MailHog/Mailpit 冒烟：纯文本、HTML、附件、内嵌图、CC/BCC、中文主题/正文。
- [ ] 真实 SMTP（QQ/163/钉钉/飞书至少一家）发送，确认 `MailResult::messageId`、日志脱敏、异常分类。
- [ ] Swoole 协程内 SMTP 发送，确认 `SWOOLE_HOOK_ALL` 下不阻塞 Worker；`mail`/`sendmail` 按文档限制使用。
- [ ] 失败注入：错误密码、错误端口、超时、坏收件人，确认异常类型与重试策略一致。

---

## 9. 附录

### 9.1 PHPMailer 7.1.1 关键行为引用

| 行为 | 文件/行 |
|---|---|
| `send()` 异常模式：`preSend()` 失败返回 false，异常模式下抛出 | `vendor/phpmailer/phpmailer/src/PHPMailer.php:1663-1679` |
| `preSend()` 收件人总数判断（to+cc+bcc） | `:1739` |
| 仅 BCC 时生成 `To: undisclosed-recipients:;` | `:1800` |
| DKIM 仅在 domain + selector 非空且私钥存在时启用 | `:1810-1815` |
| `postSend()` 按 transport 分发；异常模式下抛出 | `:1846-1881` |
| `mailSend()` / `mailPassthru()` 调用 PHP `mail()` | `:2084-2105`、`:894-897` |
| `smtpSend()` 先 RCPT，再 DATA，最后对 `$bad_rcpt` 抛 `recipients_failed` | `:2231-2306` |
| `attachAll()` 直接拼接 `Content-Type: %s; name=%s` | `:3531-3578` |
| `addStringAttachment()` / `addEmbeddedImage()` | `:4001-4037`、`:4064+` |
| `sendmailSend()` 使用 `popen()` 启动 sendmail | `:1892-1969` |
| `SMTP::authenticate()` 使用 `base64_encode($username)` | `vendor/phpmailer/phpmailer/src/SMTP.php:531-633` |

### 9.2 复现命令

```bash
# 基线测试（宿主机）
./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Mail

# 容器内基线
docker exec swoolefy-php85-v62 sh -lc \
  'cd /home/wwwroot && ./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Mail'

# 版本确认
php -r 'echo PHP_VERSION, " / Swoole ", swoole_version(), PHP_EOL;'
docker exec swoolefy-php85-v62 php -r 'echo PHP_VERSION, " / Swoole ", swoole_version(), PHP_EOL;'
```

### 9.3 审查边界与假设

- 本报告只审查 `src/Mail` 及其直接依赖的 PHPMailer 行为，不覆盖上层 Job、队列重试实现、邮件模板业务内容和 SMTP 服务商侧策略。
- 复现基于 PHP 8.4/8.5 与 PHPMailer 7.1.1；升级 PHPMailer 后 MAIL-01 的异常识别、MAIL-02 的 `attachAll()` 拼接方式需要重新确认。
- 未对真实外部 SMTP 做压测；MAIL-20 的阻塞结论基于 PHPMailer 调用路径与 Swoole hook 机制，建议在目标 Worker 配置下做一次实测。
- 报告中「已复现」均为审查期间的定向脚本结果，脚本位于 `/tmp`，未提交到仓库。

---

**审查结论**：Mail 模块的主链路设计清晰，模板/地址/附件路径的基础防护到位。审查确认的 **4 项 P1 与 20 项 P2 均已修复**：MAIL-01/03 消除了重复发信与日志污染，MAIL-02 封堵 MIME 头注入，MAIL-04 恢复对通用 SMTP 服务（SES/SendGrid）的支持；P2 覆盖了端口/加密、配置严格解析、DKIM、附件实际字节、脱敏、日志、模板缓存、收件人上限与协程阻塞告警。修复后宿主机 `OK (60 tests, 186 assertions, 1 skipped)`、容器内 `OK (60 tests, 187 assertions)`。README 与技术方案已同步端口、BCC、重试、日志、模板和协程相关说明；真实 SMTP / MailHog 冒烟仍需在联调环境按 §8.4 执行。
