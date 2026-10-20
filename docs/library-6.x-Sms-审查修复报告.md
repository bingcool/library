# library-6.x Sms 模块审查报告

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块 | Sms（`src/Sms/**/*.php`） |
| 审查日期 | 2026-10-20 |
| 审查范围 | 30 个 PHP 文件、约 2668 行；含 Gateway、Verification、ValueObject、Support、Testing、README |
| 核实方式 | 逐文件通读 + Swoole 协程实测 + vendor 源码对照 |
| 运行环境 | PHP 8.4.14 CLI，Swoole 6.1.7 ZTS，PHPUnit 11.5.57 |
| 基线测试 | `./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Sms`：61 tests / 153 assertions，全部通过 |
| 源码变更 | 无。本次仅审查，未修改 `src/Sms` |
| 复现脚本 | 审查期间使用仓库根目录 `.sms-repro.php`（未跟踪，已删除）及 `/tmp/sms-audit*.php` 临时脚本 |

结论摘要：

- 2 个 P0：号码写法未归一化导致冷却/限流可绕过、校验键不一致；验证码校验存在 TOCTOU，同一码可并发消费且 `max_verify` 在并发下失效。
- 6 个 P1：腾讯云 E.164 拼装错误、腾讯回执映射漏号/串号、验证码与限流配置缺校验、`signName` 空串覆盖、号码集合去重与 `max` 语义、配置值静默回落。
- 15 个 P2：发送与存储顺序、异常归一化、Redis 计数 TTL 原子性、日志与 PII、键命名空间、模板解析、测试替身语义、文档一致性等。
- 现有 61 个单测全部通过，但未覆盖上述号码写法、并发、腾讯回执缺行、配置边界等场景。

---

## 2. 问题总览

| 等级 | ID | 问题 | 位置 | 核实结果 | 状态 |
|---|---|---|---|---|---|
| P0 | SMS-01 | 缓存键/限流键使用原始号码，消息却用归一化号码；`verify()` 也不校验号码 | `SmsVerifier.php` 93、97-112、145-220 | 已复现 | 已修复 |
| P0 | SMS-02 | 校验码非原子消费：并发可重复通过，错误次数上限可被并发绕过 | `SmsVerifier.php` 147-175 | 已复现（协程） | 已修复 |
| P1 | SMS-03 | 腾讯云 E.164 拼装重复加区号，`00` 前缀与带 `+` 区号配置也会拼错 | `TencentGateway.php` 165-177 | 已复现 | 已修复（四地区归一化） |
| P1 | SMS-04 | 腾讯回执缺行仍判全成功；回执中的未请求号码会进入成功列表 | `SendResult.php` 118-163 | 已复现 | 已修复 |
| P1 | SMS-05 | `ttl<=0` 写入永不过期验证码；限流 `max/period<=0` 抛库级异常而非 Sms 异常 | `SmsVerifier.php` 68-76、`RedisSmsStore.php` 32-41、`DurationRateGuard.php` 31-42 | 已复现/代码确认 | 已修复 |
| P1 | SMS-06 | `SmsMessage` 的 `signName=''` 会覆盖网关默认签名，发出空签名请求 | `AliyunGateway.php` 111、`TencentGateway.php` 115 | 已复现 | 已修复 |
| P1 | SMS-07 | `PhoneList` 按原始写法去重，同一号码多写法会重复发送；`SmsMessage::to()` 丢失构造时的 `max` | `PhoneList.php` 41-55、`SmsMessage.php` 56-61 | 已复现 | 已修复 |
| P1 | SMS-08 | 配置值静默回落：`enabled='false'` 仍启用限流、验证码类型拼错退化为数字、`simulate` 带空格退化为成功 | `SmsVerifier.php` 73、`CodeGenerator.php` 45、`LogGateway.php` 60 | 已复现 | 已修复 |
| P2 | SMS-09 | 先发送成功后再写验证码，快速回执/进程中断会出现“收到短信但无码可验” | `SmsVerifier.php` 111-127 | 代码确认 | 已修复 |
| P2 | SMS-10 | `ApiExceptionFactory` 丢失 `Exception::getCode()` 中的厂商码 | `ApiExceptionFactory.php` 26-40 | 已复现 | 已修复 |
| P2 | SMS-11 | Redis 计数 `INCR` 与 `EXPIRE` 非原子；`attempts` 可能永久不过期；测试替身 TTL 语义与生产相反 | `RedisSmsStore.php` 79-91、`ArraySmsStore.php` 48-58 | 代码确认/已复现 | 已修复 |
| P2 | SMS-12 | 限流异常无 `retryAfter`，冷却提示固定显示配置的完整间隔而非剩余秒数 | `SmsVerifier.php` 103、`RateLimitExceededException.php` | 代码确认 | 已修复 |
| P2 | SMS-13 | `DurationRateGuard` 的 `prefix` 只剥离不施加，配置后并不会给键加前缀 | `DurationRateGuard.php` 31-52 | 代码确认 | 已修复 |
| P2 | SMS-14 | `LogGateway` 的 logger 经 Factory 创建时永远为 null；内存记录保留完整号码；`sendCount()` 实为保留条数 | `LogGateway.php` 57、161-179、`SmsFactory.php` 62 | 已复现/代码确认 | 已修复 |
| P2 | SMS-15 | Redis/限流底层异常不是 `SmsException`；`finally` 清理异常可能覆盖原始异常 | `SmsVerifier.php` 130-134、`DurationRateGuard.php` 31-42 | 代码确认 | 已修复 |
| P2 | SMS-16 | 模板映射为空时别名被当成真实模板码原样发给厂商 | `TemplateCodeResolver.php` 20-31 | 已复现 | 已修复 |
| P2 | SMS-17 | 验证码明文与完整手机号进入 Redis 键值；键无应用/环境命名空间；模板未白名单化 | `SmsVerifier.php` 202-230、`RedisSmsStore.php` 102-105 | 代码确认 | 已修复 |
| P2 | SMS-18 | Manager 只记录成功返回的发送，SDK 异常不落日志；日志缺少 channel/requestId；群发时一次记录全部脱敏号码 | `SmsManager.php` 90-98、126-141 | 代码确认 | 已修复 |
| P2 | SMS-19 | `RedisSmsStore::add()` 对 phpredis 返回对象做字符串转换；`\Redis` 无 `__toString()`，MULTI 场景会致命错误 | `RedisSmsStore.php` 43-59 | 代码确认 | 已修复 |
| P2 | SMS-20 | `ArraySmsStore` 无 TTL 时间推进，且 `increment` 每次重置 TTL；缺少 RedisSmsStore/并发测试 | `ArraySmsStore.php` 48-58、`tests/Sms` | 已复现 | 已修复 |
| P2 | SMS-21 | 文档与实现不一致：`sms:vl`/`sms:rl`、配置键前导空格、接口注释要求号码写法一致 | 设计文档、`VerifierInterface.php` 30-32 | 代码确认 | 已修复 |
| P2 | SMS-22 | 腾讯模板参数丢弃键名，调用方必须知道厂商占位顺序；无顺序映射配置 | `TemplateParamMapper.php` 32-46 | 代码确认 | 已修复 |

---

## 3. P0 详情

### SMS-01：号码写法进入缓存键/限流键，但消息被 PhoneList 归一化

**位置**

- `src/Sms/Verification/SmsVerifier.php`：93（校验结果被丢弃）、97-112（冷却/限流/消息）、145-175（verify）、202-230（各 key 方法）
- `src/Sms/ValueObject/PhoneList.php`：41-49（去空格与 `-()`）

**现象**

`send()` 先用 `PhoneList::make($phone)` 做校验，但返回值被丢弃；随后冷却键、限流键、验证码键全部使用原始 `$phone`，而 `SmsMessage::make($phone, ...)` 内部会再次用 `PhoneList` 去掉空格/连字符。结果是：

- 实际发给厂商的号码：`+8613800008000`
- Redis 中保存验证码的键：`code:login_code:+86 138-0000-8000`
- 用户用正常写法 `+8613800008000` 校验：`absent`
- 用户必须用发送时的原始写法 `+86 138-0000-8000` 才能校验通过

**实测**

```text
A1 send.ok: true
A2 实际发给厂商的号码: ["+8613800008000"]
A3 存储中的 key: ["code:login_code:+86 138-0000-8000"]
A4 用归一化号码 verify: 'absent'
A5 用原始写法 verify: 'ok'

1.1 三种写法发送: [true,true,true]
1.2 限流键:
["sms:rl:login_code:+86 138-0000-8000",
 "sms:rl:login_code:+8613800008000",
 "sms:rl:login_code:13800008000"]
1.4 冷却期内用带空格写法重发: NOT REJECTED
```

**影响**

1. **成本与短信轰炸**：同一真实号码用不同写法可绕过 `resend_interval` 与小时限额。若发送入口接收用户输入的手机号，攻击者可对同一目标号码反复请求验证码，耗尽短信预算。
2. **正常校验失败**：业务把用户提交的号码交给 `verify()`（通常是不带空格的规范写法）时返回 `absent`。
3. **任意键**：`verify()` 完全不校验号码。实测 `verify('not-a-phone')`、`verify('')`、5000 字符号码均直接返回 `absent`，不抛 `InvalidPhoneException`。
4. 模板同样直接拼进键，业务若把模板名暴露给用户，还会产生额外配额维度（每个模板各自一套冷却和限额）。

**根因**

`PhoneList` 只负责“消息 DTO”内的归一化，没有为 `SmsVerifier` 提供统一的“号码键”。`send()` 与 `verify()` 对号码的处理不对称。

**修复建议**

1. 在 `PhoneList` 或新增 `Support/PhoneNumber` 中提供 `canonical(string): string`：
   - 去分隔符；
   - `00` 开头转 `+`；
   - 中国号码统一 `+86`（至少把 `+86`、`86`、裸 11 位归一为同一键）；
   - 其他国际号码保留数字与 `+`。
2. `SmsVerifier::send()`：
   ```php
   $phones = PhoneList::make($phone);
   $wirePhone = $phones->first();          // 发厂商的写法
   $keyPhone = PhoneNumber::canonical($wirePhone); // 所有 Redis key 用这个
   ```
3. `SmsVerifier::verify()` 同样先 `PhoneList::make($phone)` / canonical，非法号码直接抛 `InvalidPhoneException`（或按业务约定返回 `absent`，但必须一致）。
4. 所有 `codeKey/cooldownKey/attemptsKey/traceKey/rateKey` 只接受 canonical 号码；模板名做白名单或只允许 `[A-Za-z0-9_]+`。
5. 更新 `VerifierInterface` 中“号码须与 send() 写法一致”的注释，修复后应改为“号码会被归一化”。

**验收标准**

- `send('+86 138-0000-8000')` 后，`verify('+8613800008000', $code)`、`verify('13800008000', $code)` 均返回 `ok`。
- 首次发送后，用上述任意写法在冷却期内重发都抛 `RateLimitExceededException`。
- 三种写法的限流键完全相同，`ArrayRateGuard` 计数为 1。
- `send('not-a-phone')` 抛 `InvalidPhoneException`；`verify('', ...)` 不再访问 Redis。

---

### SMS-02：验证码校验是“先比较后计数再删除”，并发下可重复消费与绕过尝试次数

**位置**

`src/Sms/Verification/SmsVerifier.php` 147-175

```php
$stored = $this->store->get($this->codeKey($phone, $template));
if ($stored === null) {
    return $this->missingCodeResult($phone, $template);
}

$input = trim($input);
if ($input !== '' && hash_equals($stored, $input)) {
    $this->store->forget($this->codeKey($phone, $template));
    // 后续 forget ...
    return VerifyResult::ok();
}

$attempts = $this->store->increment($this->attemptsKey($phone, $template), $this->ttl);
if ($this->maxVerify > 0 && $attempts >= $this->maxVerify) {
    $this->store->forget($this->codeKey($phone, $template));
    $this->store->put($this->traceKey($phone, $template), 'locked', $this->traceTtl());
    return VerifyResult::locked();
}
```

`get`、`hash_equals`、`forget`、`increment` 是四次独立操作，中间没有任何原子性保证。

**实测**

同一验证码并发校验两次（`SlowStore` 放大竞态）：

```text
S1 同一验证码并发校验两次的结果: ["ok","ok"]
```

20 个并发错误输入（`max_verify=5`）：

```text
2.1 concurrent wrong-guess statuses: {"mismatch":4,"locked":16}
2.2 attempts after concurrency: "20"
```

说明 20 个请求都完成了“读取 + 比较”，只是后 16 个返回 locked；如果其中任意一个输入正确，它会在码被删除前直接返回 `ok`。有效猜测次数等于并发度，而不是 `max_verify`。

**影响**

1. 一次性验证码可被重复使用（并发请求可同时通过）。
2. `max_verify` 防暴力在并发下失效。6 位数字码空间只有 100 万，攻击者可对一个受害号码发起大量并行校验，显著提高命中率。
3. 成功校验清理 `cooldown/trace` 时，可能删掉并发新发送产生的冷却/痕迹，造成状态错乱。

**根因**

校验逻辑跨越多个 Redis 原语，`SmsStoreInterface` 只提供 `get/put/add/forget/increment`，没有“比较并删除”或“原子预占一次尝试”的能力。`hash_equals` 只解决时序侧信道，不解决 TOCTOU。

**修复建议**

1. 在 `SmsStoreInterface` 增加原子原语（生产用 Lua，测试替身用数组实现）：
   ```php
   /** 原子自增并返回；保证首次创建即带 TTL */
   public function reserveAttempt(string $key, int $ttl): int;

   /** 仅当当前值等于 expected 时删除，返回是否删除成功 */
   public function deleteIfEquals(string $key, string $expected): bool;
   ```
2. `verify()` 调整顺序：
   - 先 `reserveAttempt()`。若次数超过 `max_verify`，删除码、写 `locked`，直接返回 locked，不再比较输入。
   - 再 `deleteIfEquals(codeKey, stored/输入)`。只有删除成功才返回 `ok`；删除失败说明码已被并发消费或替换，返回 mismatch/absent。
   - 清理 attempts/trace/cooldown 时尽量放在同一个 Lua 脚本或至少保证失败不影响“码已被消费”的事实。
3. 更彻底的做法是把 `GET code`、`INCR attempts`、`DEL code`、`SET trace locked` 收进一个 Redis Lua 脚本（由 `RedisSmsStore` 实现），`SmsVerifier` 只调用一个语义化方法。注意 Lua 内不能使用 PHP `hash_equals`；可存 HMAC 后的定长值再比较，或接受 Redis 内部字符串比较（网络时延远大于比较时延）。
4. 可选：对 `verify` 增加按手机号/IP 的独立校验限流，避免攻击者通过“先耗尽次数再锁定”对用户造成 DoS。

**验收标准**

- 两个协程同时用正确码校验：恰好一个 `ok`，另一个为 `mismatch`/`absent`。
- 100 个协程并发错误码（`max_verify=5`）：最多 5 个进入比较逻辑，其余直接 locked；`attempts` 不超过上限（或按明确设计最多多 1）。
- 并发校验期间发生新 `send()`：旧码校验不能删除新码、不能删除新冷却。
- 现有 `testVerifyLocksOutAfterMaxAttempts` 等 61 个用例保持通过。

---

## 4. P1 详情

### SMS-03：腾讯云 E.164 拼装重复加区号（已修复）

**状态**：2026-10-20 修复。新增 `Support\PhoneNormalizer`，腾讯云/阿里云网关与验证码键统一走 E.164 归一化；覆盖 `+` / `00` / 已带国家码写法，并支持大陆、香港、澳门、台湾四地区。配置键统一为 `default_region`（CN/HK/MO/TW 或 86/852/853/886）。腾讯云额外校验「全境内或全境外」，阿里云按国内 11 位、国际「区号+号码」输出。回归见 `tests/Sms/PhoneNormalizerTest.php` 与 `SmsFixTest` 新增用例。

**位置**：`src/Sms/Gateway/TencentGateway.php` 165-177

```php
foreach ($phones as $phone) {
    if (str_starts_with($phone, '+')) {
        $out[] = $phone;
    } else {
        $out[] = '+' . $this->countryCode . $phone;
    }
}
```

**实测**

```text
E1 无 + 的 86 号码: ["+868613800008000"]
E2 default_country_code=+86: ["++8613800008000"]
```

**现象与影响**

- 输入 `8613800008000`（带国家码但无 `+`）被拼成 `+868613800008000`，厂商会判号码非法或发送到错误号码。
- 配置 `default_country_code => '+86'` 时拼成 `++8613800008000`。
- 输入 `008613800008000`（国际 `00` 前缀）同样错误。
- 非中国号码不带 `+` 时会被强行加 `+86`，例如美国号码 `14155552671` 变成 `+8614155552671`。

**修复建议**

统一由号码归一化组件产出 E.164，再交给腾讯网关；网关内至少做：

```php
$cc = ltrim($this->countryCode, '+');
if ($cc === '' || !ctype_digit($cc)) {
    throw new InvalidProviderException('Tencent sms default_country_code must be digits');
}

foreach ($phones as $phone) {
    if (str_starts_with($phone, '+')) {
        $out[] = $phone;
    } elseif (str_starts_with($phone, '00')) {
        $out[] = '+' . substr($phone, 2);
    } else {
        $out[] = '+' . $cc . $phone;
    }
}
```

并对“号码已含国家码但无 `+`”明确约定（建议直接要求国际号码必须为 E.164，国内号码走 `default_country_code`），或在归一化层处理 `86`/`1` 等前缀，避免歧义。

**验收标准**

| 输入 | 期望 |
|---|---|
| `13800008000` | `+8613800008000` |
| `+8613800008000` | `+8613800008000` |
| `8613800008000` | `+8613800008000` |
| `008613800008000` | `+8613800008000` |
| `default_country_code='+86'` | 不出现 `++` |
| `14155552671` + 默认 86 | 明确抛错或要求 `+1...`，不能静默拼成 `+861...` |

---

### SMS-04：腾讯云回执映射漏号仍判成功、未请求号码进入成功列表（已修复）

**状态**：2026-10-20 修复。`fromTencentBody()` 仅接受请求集合内的号码；缺 `PhoneNumber` 的行不参与归属；厂商漏回的请求号码补 `MissingStatus` 失败并使 `ok=false`；成功/失败列表按号码去重；网关回执通过 E.164 → 原始号码映射对齐港澳台写法。

**位置**：`src/Sms/ValueObject/SendResult.php` 118-163

**实测**

```text
F1 请求两个号码，腾讯只回一条:
  ok: true
  successPhones: ["13800008000"]
  failedPhones: []
  未回执的 13900009000 是否出现在任何列表: false

P1 腾讯回执包含未请求号码 +8613900009999:
  successPhones: ["13800008000","+8613900009999"]
  ok: true
```

**影响**

- 批量发送时，厂商漏回的号码被静默忽略：业务认为全部成功，实际有用户未收到短信。
- 回执中出现的未请求号码被加入 `successPhones`，业务对账会串号。
- 回执行缺少 `PhoneNumber` 时会被归一化为空串并可能进入成功列表。
- `SmsVerifier` 使用腾讯单号码发送时，若回执缺行，仍会把验证码写入 Redis 并返回 `ok`，用户实际收不到短信。

**修复建议**

1. 先建立“请求号码 canonical → 原始号码”的映射。
2. 遍历回执时只匹配请求集合中的号码；未请求号码单独记录或忽略，不能进入 `successPhones`。
3. 遍历结束后，对没有回执的请求号码补 `failedPhones`（例如 `code='MissingStatus'`、`reason='provider did not return status'`），并令 `ok=false`。
4. `ok` 的条件改为：请求的每个唯一号码都出现在成功列表且没有失败记录。
5. 对 `successPhones` / `failedPhones` 去重。

**验收标准**

- F 场景：`ok=false`，`13900009000` 出现在 `failedPhones`。
- P 场景：`successPhones=['13800008000']`，未请求号码不出现，`ok=true`。
- 回执缺 `PhoneNumber` 的行不进入成功列表。
- 现有 `testTencentRequestIdComesFromResponseNotSerial` 保持通过。

---

### SMS-05：验证码 TTL 与限流参数无校验，错误配置会造成永不过期或非 Sms 异常（已修复）

**状态**：2026-10-20 修复。`SmsVerifier` 构造期校验 `ttl>=1`、`max_verify>=1`、`resend_interval>=0`、限流 `max/period>=1`、`rate.key` 必须含 `{phone}`、`codeParamKey` 非空；`RedisSmsStore` 对 `ttl<=0` 抛 `InvalidConfigException`；`DurationRateGuard` 对非法 `max/window` 提前抛 Sms 异常。

**位置**

- `src/Sms/Verification/SmsVerifier.php` 66-81（配置读取，无范围校验）
- `src/Sms/Verification/RedisSmsStore.php` 32-41（`ttl<=0` 退化为 `SET` 无过期）
- `src/Sms/Verification/DurationRateGuard.php` 31-42
- `vendor` 内 `src/RateLimit/DurationLimiter.php` 82-100（`empty($limitNum)` / `empty($windowSizeTime)` 抛 `Swoolefy\Library\Exception\RateLimitException`）

**实测/代码确认**

```text
B1 ttl=0 时 code key 的 TTL: 0        // Array 记录；RedisSmsStore 直接 set，无过期
B2 ttl=0 且 period=0 时 trace key 的 TTL: 0
H1 max=0 异常类: Swoolefy\Library\Exception\RateLimitException
H2 是否 SmsException: false
```

- `ttl=0`：`RedisSmsStore::put()` 走 `set()`，验证码**永不过期**；`traceTtl()` 在 `ttl=0` 且 `ratePeriod=0` 时返回 0，发送痕迹也永不过期。
- `ttl` 为负数同样走无过期分支。
- `rate_limit.max=0` 或 `period=0`：`DurationLimiter::isLimit()` 抛库级 `RateLimitException`，不属于 `SmsException`，业务按 README 的 `catch (SmsException)` 捕获不到；在 Swoole 请求中表现为 500。
- `rate_limit.key` 缺少 `{phone}` 时，同一模板下所有用户共享一个限流桶，属于严重的误配置，但没有任何告警。
- `codeParamKey=''`、`rate.key=''` 等也没有校验。

**修复建议**

1. `SmsVerifier` 构造函数集中校验并快速失败（建议新增 `Exception/InvalidConfigException extends SmsException`）：
   - `ttl >= 1`（并设合理上限，如 1800）；
   - `max_verify >= 1`，若要支持 0=不限，必须显式写文档并给出警告；
   - `resend_interval >= 0`；
   - `rate.enabled` 时 `max >= 1`、`period >= 1`；
   - `rate.key` 必须包含 `{phone}`，建议同时要求 `{template}`；
   - `length` 在 4-12（`CodeGenerator` 已校验，但可提前给出配置错误）；
   - `codeParamKey !== ''`。
2. `RedisSmsStore::put()` 对 `ttl <= 0` 直接抛异常（或明确提供“永久”的独立方法），不要让 OTP 静默永久化。
3. `DurationRateGuard::tooMany()` 对 `max/window <= 0` 提前抛 `SmsException` 子类，或在 `SmsVerifier` 层保证不会传入非法值。
4. 为配置校验补一组表驱动测试。

**验收标准**

- `ttl=0/-1`、`max_verify` 类型错误、`rate.max=0`、`period=0`、`rate.key` 缺 `{phone}` 均在构造期抛 `SmsException` 子类，且错误信息指出具体配置键。
- 正常配置 (300/5/60/5/3600) 行为不变，现有测试通过。
- `RedisSmsStore::put($k, $v, 0)` 不再创建无过期键。

---

### SMS-06：`signName=''` 覆盖网关默认签名（已修复）

**状态**：2026-10-20 修复。阿里云/腾讯云网关对消息级 `signName` 做 `trim`，为空或空白时回落网关默认签名；非空值 trim 后覆盖。

**位置**

- `src/Sms/Gateway/AliyunGateway.php` 111：`'signName' => $message->signName ?? $this->signName`
- `src/Sms/Gateway/TencentGateway.php` 115：`'SignName' => $message->signName ?? $this->signName`

**实测**

```text
Q1 SmsMessage signName="" 时的 SignName: ''
```

**影响**

`SmsMessage::make(..., signName: '')` 或 `$message->sign('')` 会让请求发送空签名，厂商返回签名错误；业务以为使用了网关默认签名。如果签名来自表单/配置且可能为空字符串，这是实际可触发的故障。

**修复建议**

```php
$signName = ($message->signName !== null && trim($message->signName) !== '')
    ? trim($message->signName)
    : $this->signName;
```

两个网关都补同样的判断，并在 `SmsMessage::sign()` 或网关构造时校验非空。

**验收标准**

- `signName=''` 时使用配置默认签名。
- `signName='   '` 同样回落默认。
- `signName='其他签名'` 正常覆盖。
- 新增 Aliyun/Tencent payload 单测。

---

### SMS-07：`PhoneList` 去重不归一化，`SmsMessage::to()` 丢失 `max`（已修复）

**状态**：2026-10-20 修复。`PhoneList` 按 canonical E.164 去重并保留首次写法（无法判断归属时按原写法）；`SmsMessage` 保存构造时的 `max`，`to()` 复用该上限，`make()` 新增可选 `$max`。

**位置**

- `src/Sms/ValueObject/PhoneList.php` 41-55
- `src/Sms/SmsMessage.php` 56-61

**实测**

```text
J1 PhoneList::make(['+8613800008000','8613800008000','13800008000'])->count(): 3
I1 max=2 的 SmsMessage 用 to() 换成 3 个号码: 未抛异常，count=3
```

**影响**

- 同一真实号码的三种写法会发给厂商三次，产生三条短信费用；批量群发时成本放大。
- `SmsMessage` 构造时传入 `max=2`（例如腾讯单请求限制）后，链式 `to()` 会恢复默认 1000，破坏调用方的上限意图；虽然网关还有二次校验，但错误发生得更晚，且 `to()` 的语义不一致。

**修复建议**

1. `PhoneList` 增加 canonical 去重：以归一化后的号码作为去重键（至少 `+86`/`86`/裸 11 位统一），保留第一次出现的原始写法用于厂商请求；或在构造时直接统一为 E.164，再由各网关格式化。
2. `SmsMessage` 保存构造时的 `$max`：
   ```php
   private int $max;
   public function __construct(..., int $max = PhoneList::DEFAULT_MAX) {
       $this->max = $max;
       $this->phones = PhoneList::make($phones, $max);
   }
   public function to(string|array $phones): self {
       $this->phones = PhoneList::make($phones, $this->max);
       return $this;
   }
   ```
3. `SmsMessage::make()` 增加可选 `$max` 参数，便于按网关限制构造消息。

**验收标准**

- `PhoneList::make(['+8613800008000','13800008000'])->count() === 1`。
- 三种写法的 `SmsMessage` 只产生一个收件号码。
- `new SmsMessage([...], 't', [], null, 2)->to([1,2,3])` 抛 `InvalidPhoneException`。
- 现有号码测试保持通过。

---

### SMS-08：配置值静默回落，测试/生产行为与配置意图不符（已修复）

**状态**：2026-10-20 修复。`CodeGenerator` 对类型做 `trim + strtolower` 并限定 `number|alnum`；`LogGateway` 对 `simulate` 同样规范化并拒绝未知值；`rate_limit.enabled` 使用严格布尔解析，非法值抛 `InvalidConfigException`。

**位置**

- `src/Sms/Verification/SmsVerifier.php` 73：`(bool) ($rate['enabled'] ?? true)`
- `src/Sms/Verification/CodeGenerator.php` 45：非 `alnum` 一律走数字池
- `src/Sms/Gateway/LogGateway.php` 60：`strtolower()` 但未 `trim()`
- 其他：`LogGateway` 未知 `simulate` 值落到 `default => success`

**实测**

```text
C1 CodeGenerator(6, 'numeric')->getType(): 'numeric'    // 实际按 number 生成
C2 生成结果纯数字: true
D1 simulate=' failure ' 的发送结果: true                 // 没有模拟失败
4.1 (bool)'false': true
4.2 rate_limit.enabled='false' 仍被限流: RateLimitExceededException
```

**影响**

- 本地/单测用 `simulate='failure '` 以为在验证失败分支，实际一直成功，测试形同虚设。
- `type` 配置拼写错误（`numeric`、`alnum `、`ALNUM`）会静默退化为纯数字，若业务以为开启了字母数字码，实际熵显著降低。
- 字符串 `'false'`（常见于 env 配置）被 PHP 强转为 `true`，限流仍然开启；反之 `'0'` 是 false。配置来源不同会导致行为不一致。
- 未知 `simulate` 值静默成功，掩盖配置错误。

**修复建议**

1. `CodeGenerator` 构造时 `strtolower(trim($type))` 并校验 `in_array(['number','alnum'], true)`，未知值抛 `SmsException`；可兼容 `numeric` → `number` 并记录告警（若要兼容）。
2. `LogGateway` 构造时 `trim(strtolower(...))`，未知 `simulate` 抛 `InvalidProviderException`。
3. `rateEnabled` 使用严格布尔解析：
   ```php
   $enabled = filter_var($rate['enabled'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
   if ($enabled === null) { throw new InvalidConfigException(...); }
   ```
4. 所有“枚举型”配置（type、simulate、format 等）统一校验，避免 `default` 静默兜底。

**验收标准**

- `simulate=' failure '`、`'PARTIAL'` 能正确进入对应模式；未知值抛错。
- `type='numeric'` 抛错或按显式别名处理；`ALNUM` 生成字母数字。
- `enabled='false'` 真正关闭限流；非法布尔字符串在构造期抛错。
- 新增配置表驱动测试。

---

## 5. P2 详情与优化项

### SMS-09：先发送后存码，存在“短信已收到但无码可验”的窗口（已修复）

**状态**：2026-10-20 修复。改为先落码再发送；发送失败/抛错回滚 code 并释放冷却，成功后才写 trace 与重写冷却。复核后修订：发送成功后的 attempts/trace/cooldown 写入改为 best-effort，记账失败不再抛错，也不会释放已生效的冷却。

**位置**：`src/Sms/Verification/SmsVerifier.php` 111-127

当前流程是：生成码 → `send()` → 成功后 `put(code)`。短信在厂商响应前可能已经到达用户手机；如果用户极快提交、或进程在 `send()` 返回后崩溃，验证码将不存在于 Redis，`verify()` 返回 `absent`。此外 `put` 失败时 SMS 已发出，无法回滚。

**建议**：先写入 code（状态可以是 pending），再发送；发送失败/抛错时删除 code/trace 并释放冷却；成功后再写 trace 和重写冷却。这样用户收到短信时码一定可验，且失败时不会留下可用码。

**验收标准**：模拟慢网关，在 `send()` 返回前校验能查到 code；发送失败后 code/trace 被清理；现有失败分支测试保持。

---

### SMS-10：`ApiExceptionFactory` 丢失 `Exception::getCode()`（已修复）

**状态**：2026-10-20 修复。providerCode 依次尝试 `getErrorCode()`、public `code`、`getCode()`（过滤 0/空）；requestId 增加 `getData()` 来源。

**位置**：`src/Sms/Support/ApiExceptionFactory.php` 26-40

**实测**：

```text
N1 ApiExceptionFactory::fromSdk('aliyun', new \Exception('boom', 123))->getProviderCode(): NULL
```

`isset($e->code)` 只能读到 public 属性（阿里云 `TeaError` 可以），普通 `\Exception` 的 `code` 是 protected，读不到。腾讯有 `getErrorCode()` 不受影响；阿里云部分通用异常会丢码。

**建议**：在 `getErrorCode` 和 public `code` 之后补 `$e->getCode()`（过滤 0 / 非字符串），并补充 `getData()` / `requestId` 取值来源；同时注意 SDK 异常 message 可能含请求细节，落日志前考虑脱敏。

---

### SMS-11：Redis 计数 TTL 非原子，测试替身语义与生产相反（已修复）

**状态**：2026-10-20 修复。Redis `increment` 改为单条 Lua（INCR + TTL 检查/修复）；`ArraySmsStore` 改为首次设置 TTL，并新增虚拟时钟与 `ttl()`。

**位置**：`src/Sms/Verification/RedisSmsStore.php` 79-91、`src/Sms/Testing/ArraySmsStore.php` 48-58

`incr` 与 `expire` 是两条命令；进程在中间退出、或键已存在但无 TTL（历史数据/前一次崩溃）时，`attempts` 永远不会过期。`ArraySmsStore::increment` 每次都会重置 TTL，`RedisSmsStore` 只在第一次设置，测试无法发现生产差异。

**建议**：用 Lua 原子执行 `INCR`，首次创建时 `EXPIRE`；或每次 `INCR` 后无条件 `EXPIRE`（多一条命令但简单）。测试替身改为“首次设置 TTL”，并增加时间推进能力。补 RedisSmsStore 的桩测试。

---

### SMS-12：限流异常缺少 `retryAfter`，冷却提示不精确（已修复）

**状态**：2026-10-20 修复；复核后修订为真实剩余窗口。`RateLimitExceededException` 增加 `reason()` / `retryAfter()`；冷却命中读取剩余 TTL，窗口限流经 `RateGuardInterface::remaining()` → `DurationLimiter::getRemainingTime()` 返回最早一次消费滑出窗口的秒数，查询失败退回 `period`。

**位置**：`src/Sms/Verification/SmsVerifier.php` 103、`src/Sms/Exception/RateLimitExceededException.php`

冷却期命中时消息固定为“请 N 秒后重试”，N 是配置值而不是剩余时间；窗口限流也没有告诉调用方何时可重试。业务无法设置 `Retry-After`，前端体验差。

**建议**：为 `RateLimitExceededException` 增加 `reason`（cooldown/window）与 `retryAfter`；冷却可用 store 读取剩余 TTL（需要新增 `ttl()` 原语）或至少返回配置值并标注；窗口限流可扩展 `DurationLimiter` 返回剩余窗口。同步更新 README。

---

### SMS-13：`DurationRateGuard` 的 prefix 只剥离不施加（已修复）

**状态**：2026-10-20 修复。prefix 改为施加（键已含前缀时不重复）。复核后修订：默认 prefix 改为 `''`，默认键保持 `_rate_limit:sms:rl:...`，避免 `sms:rg:` 与 `sms:rl:` 叠加；显式传入 prefix 时仍施加。

**位置**：`src/Sms/Verification/DurationRateGuard.php` 31-52

```php
private function strip(string $key): string
{
    if ($this->prefix !== '' && str_starts_with($key, $this->prefix)) {
        return substr($key, strlen($this->prefix));
    }
    return $key;
}
```

构造函数允许传入 `$prefix`，但默认的 `SmsVerifier` 键是 `sms:rl:...`，不以 `sms:rg:` 开头，因此前缀从未施加；如果用户自定义 `prefix='sms:'`，又会意外剥掉键自身的 `sms:`。API 语义具有误导性。

**建议**：明确前缀策略：要么始终 `$prefix . $key`（若未包含），要么删除该参数，避免“配置了却无效”。增加断言测试。

---

### SMS-14：`LogGateway` 的 logger/PII/计数语义（已修复）

**状态**：2026-10-20 修复。`LogGateway` 默认只存打码号码（`keep_phones` 可显式保留）、`sendCount()` 改为累计次数，新增 `LoggedSms` 快照。复核后修订：移除 `LogGateway` 内部 logger 与 `log()`，`SmsFactory` 不再注入 logger，发送日志只由 Manager 统一记录，避免双写。

**位置**：`src/Sms/Gateway/LogGateway.php` 57、161-179；`src/Sms/SmsFactory.php` 62

- `LogGateway` 构造函数支持 `LoggerInterface`，但 `SmsFactory::make()` 只传 `$config`，通过 Manager 创建的 `LogGateway` 永远拿不到 logger；Manager 自己的 `logSend()` 能记录，但 `LogGateway` 内部的 `log()` 是死代码。
- `remember()` 只对 `params` 打码，完整手机号仍保留在 `sent`/`lastMessage()` 中；类注释写的是“已发送消息的打码副本”，与实现不完全一致。
- `sendCount()` 返回的是保留队列长度（上限 32），不是累计发送次数；发送 40 次后返回 32，容易被测试误用。

**建议**：由 `SmsFactory`/`SmsManager` 将 logger 注入内置网关（或移除未使用的 logger 参数）；明确 PII 策略（默认存脱敏号码，或增加显式 `keepPhones` 选项）；增加 `totalSends` 或重命名 `sendCount()`。注意 LogGateway 是有状态的，不应被跨协程共享。

---

### SMS-15：基础设施异常未归一化为 `SmsException`，`finally` 可能覆盖原始异常（已修复）

**状态**：2026-10-20 修复。新增 `SmsStorageException`；Verifier 对 store/guard 调用统一包装；finally / safeForget 吞掉清理异常，不覆盖原始异常或返回值。

**位置**：`src/Sms/Verification/SmsVerifier.php` 130-134、`src/Sms/Verification/DurationRateGuard.php` 31-42、`src/Sms/Support/RedisComponent.php`

- Redis 连接/命令异常、`DurationLimiter` 的 `RateLimitException` 都不是 `SmsException` 子类；README 建议 `catch (SmsException)` 无法覆盖这些情况。
- `finally { if ($claimed) $this->store->forget($cooldownKey); }` 中 `forget()` 如果抛异常，会覆盖原始发送异常；发送失败返回 `SendResult` 时也会因清理异常改为抛错。

**建议**：新增 `SmsStorageException` / 在 store 与 guard 内包装底层异常；`finally` 内清理使用 try/catch 并记录日志，保证原始异常/返回值优先。明确文档中的异常边界。

---

### SMS-16：模板映射为空时把别名当真实模板码（已修复）

**状态**：2026-10-20 修复。未配置 `templates` 时只有 `isRawCode()` 放行，别名抛 `InvalidProviderException`；`FakeSmsGateway` 同步生产规则。

**位置**：`src/Sms/Support/TemplateCodeResolver.php` 20-31

**实测**：

```text
G1 TemplateCodeResolver::resolve([], 'login_code'): 'login_code'
```

生产网关在未配置 `templates` 时会把 `login_code` 原样发给阿里云/腾讯云，厂商报模板不存在，错误延迟到网络请求才暴露；README 却写“未知别名抛 `InvalidProviderException`”。对 `LogGateway`/`FakeSmsGateway` 保留别名放行可以理解，但生产网关应 fail fast。

**建议**：区分“原始码模式”和“别名模式”：`templates` 为空时，只有匹配 `isRawCode()` 的输入放行，否则抛配置异常；或要求生产网关显式配置 `allow_raw_alias=false`。同步更新 README。

---

### SMS-17：Redis 键值隐私与命名空间（已修复）

**状态**：2026-10-20 修复。新增 `key_prefix`、`hash_key`（HMAC 手机号/OTP）、`allowed_templates` 与模板字符校验；默认行为保持原键格式。

**位置**：`src/Sms/Verification/SmsVerifier.php` 202-230、`src/Sms/Verification/RedisSmsStore.php` 26-30、102-105

- 键中包含完整手机号和模板名（`code:login_code:+8613800008000`），值中是验证码明文。Redis 备份、`KEYS`/`SCAN`、慢日志、监控都会暴露 PII 与可用 OTP。
- 默认前缀只有 `sms:`，同一 Redis 实例上的多个应用/环境会互相覆盖（特别是 `code:login_code:<phone>`）。
- 模板名直接拼接，若业务传入用户可控字符串，可制造键冲突/超长键。

**建议**：增加可配置 `key_prefix`（含应用/环境）；对手机号做 HMAC 后入键；验证码存 HMAC/Hash（低熵 OTP 需带服务端密钥的 HMAC，否则可被彩虹表）；模板名白名单/字符校验。注意这会改变现有键格式，需要一并调整测试和部署策略。

---

### SMS-18：Manager 日志覆盖不足与体量问题（已修复）

**状态**：2026-10-20 修复。网关异常也落 error 日志；补充 channel / request_id / failed_count / duration_ms；号码最多记 5 个并标记截断；日志异常不影响主流程。复核后修订：`SmsApiException` 与 `InvalidPhoneException` 的原始 message 不再落日志（可能含请求细节或完整号码），仅记录异常类 / provider_code / request_id。

**位置**：`src/Sms/SmsManager.php` 90-98、126-141

- `sendVia()` 中网关抛 `SmsApiException` 时不会执行 `logSend()`，SDK/网络失败没有任何结构化日志（只有异常向上抛）。
- 日志只有 driver 名（`$gateway->name()`），没有配置里的 channel 名；两个通道使用同一 driver 时无法区分。
- 日志没有 `requestId`、失败号码数、耗时；群发 1000 个号码时 `phones` 数组会有 1000 个脱敏号码，日志体量较大。

**建议**：在 `try/catch` 中记录异常（含 providerCode/requestId、脱敏号码、channel），再重抛；成功日志补 `channel`、`requestId`、`failedCount`；`phones` 只记录前 N 个或仅记录 count。记录日志本身也应避免抛出影响主流程。

---

### SMS-19：`RedisSmsStore::add()` 对 phpredis 返回对象做字符串转换（已修复）

**状态**：2026-10-20 修复。`add()` 显式识别 `true` / `'OK'` / Status / `\Redis` / `\RedisCluster`，不再对 phpredis 对象做字符串转换。

**位置**：`src/Sms/Verification/RedisSmsStore.php` 43-59

```php
return $result === true || $result === 'OK' || (is_object($result) && (string) $result === 'OK');
```

已确认 `\Redis` 类没有 `__toString()`。在 MULTI/PIPELINE 模式下 phpredis 的 `set()` 会返回 `Redis` 对象，此时 `(string) $result` 会抛 `Error: Object of class Redis could not be converted to string`。正常非事务模式返回 bool，所以当前主路径未触发，但这是潜伏问题。Predis 的 `Status` 对象可字符串化，需要区别处理。

**建议**：显式判断驱动/返回值类型：

```php
if ($result === true || $result === 'OK') return true;
if ($result instanceof \Predis\Response\Status) return (string) $result === 'OK';
if ($result instanceof \Redis || $result instanceof \RedisCluster) return true;
return false;
```

并补一个 MULTI 桩测试。

---

### SMS-20：测试替身语义与覆盖缺口（已修复）

**状态**：2026-10-20 修复。`ArraySmsStore` 支持 `advance()` 虚拟时钟与 `ttl()` 过期语义；`ArrayRateGuard` 对齐参数校验；`FakeSmsGateway` 对齐模板规则；新增 Redis store 桩测试。

**位置**：`src/Sms/Testing/ArraySmsStore.php`、`tests/Sms/*`

- `ArraySmsStore` 没有时间推进，TTL 只记录不生效；`increment` 每次重置 TTL（与 RedisSmsStore 不一致）。
- `ArrayRateGuard` 忽略 window（注释已说明），但 `max<=0` 的行为与生产抛异常不同。
- `FakeSmsGateway::templateCode()` 对未知别名始终返回别名，与生产 `TemplateCodeResolver` 不一致。
- 没有 `RedisSmsStore` 的单测（add/get/increment 的 phpredis/Predis 分支），没有并发校验、号码写法、腾讯回执缺行、配置边界测试。
- 原 `.sms-repro.php` 临时脚本已删除；相关场景已转成 `tests/Sms` 的 PHPUnit 用例，不再保留 shim。

**建议**：为 store/guard 增加可注入的 Redis 连接桩；补并发协程测试（Swoole Coroutine）、号码矩阵、回执边界、配置校验；让测试替身与生产语义一致（或明确标注差异）。

---

### SMS-21：文档一致性（已修复）

**状态**：2026-10-20 修复。README / 设计文档的 `sms:vl`、前导空格、无请求态与打码描述已更新；移除 `SmsVerifier::normalizeKeys()`；`VerifierInterface` 注释已在上轮修正。复核后修订：构造期检测 `verification` / `rate_limit` 中带前后空白的配置键并抛 `InvalidConfigException`，不回退兼容、也不静默落默认值。

**位置**：`docs/library-6.x-Sms短信技术方案.md`、`src/Sms/README.md`、`src/Sms/Contracts/VerifierInterface.php` 30-32

- 设计文档验证码限流示例用 `sms:vl:{template}:{phone}`，README/代码默认是 `sms:rl:{template}:{phone}`。
- 设计文档配置里 `' resend_interval'` 带前导空格；代码用 `normalizeKeys()` 做了兼容，但最好修正文档并去掉这段特判。
- `VerifierInterface` 写着“号码须与 send() 传入的写法一致”，这是在描述 SMS-01 的缺陷而非合理契约；修复后应删除。
- README 称“Gateway 无请求态”，但 `LogGateway` 明确有状态（开发网关），建议在 README 中补充例外。
- README 称 `LogGateway` 记录“打码副本”，实际仅 params 打码，号码完整保留（见 SMS-14）。
- 设计文档称 `PhoneList` 负责“厂商单次最大号码数限制”，实际是通用默认 1000，厂商限制在各自 Gateway 中检查。

---

### SMS-22：腾讯模板参数丢弃键名，调用方必须知道占位顺序（已修复）

**状态**：2026-10-20 修复。`TemplateParamMapper::toTencent()` 支持 `$order`；腾讯网关新增 `param_order` 配置，按键名顺序取值并校验缺失/多余参数。

**位置**：`src/Sms/Support/TemplateParamMapper.php` 32-46

`toTencent()` 直接 `array_values()`，`SmsMessage::$params` 是关联数组，但腾讯 `TemplateParamSet` 是按 `{1}`、`{2}` 顺序的列表。业务传入 `['name' => '张三', 'code' => '123456']` 时，实际发送成 `['张三','123456']`；如果模板占位顺序是 code 在前，内容就会错位，而且没有任何校验。

**建议**：在 `SmsMessage`/网关配置中支持“腾讯参数顺序”映射（例如 `param_order: ['code','name']`），或要求 `SmsMessage` 参数按顺序传入并在文档中明确；至少增加一个 payload 单测覆盖多参数场景。

---

### SMS-23：架构层优化（非缺陷）

- 无网关故障转移/重试：设计上交给框架 `Job`，但同步调用方遇到瞬时网络错误只能自行重试；可考虑 `sendWithFallback()` 或明确的错误分类。
- 无批量发送封装：当前由业务循环或 Job 分片；可提供按网关上限自动分批的 helper。
- 无指标/钩子：发送量、成功率、厂商错误码、验证码校验失败率等无法直接采集；可增加 PSR-3 之外的 metrics hook。
- 验证码文案硬编码中文，国际化场景需业务二次包装。
- `ClientTimeout` 只支持整数秒，`0.5` 会退化为默认 5 秒；如需亚秒级超时可扩展为 float/毫秒配置。

---

## 6. 经核查不成立或可接受的点

以下为审查中重点核对但未发现缺陷的项，避免后续重复排查：

1. **同键并发发送**：`SmsStoreInterface::add()` 使用 SET NX 原子占位，现有测试和实测均能保证同一写法下只发一条；问题只在号码写法不一致时（SMS-01）。
2. **`hash_equals`**：常量时间比较使用正确；它解决时序攻击，不解决 SMS-02 的 TOCTOU。
3. **`DurationLimiter` 协程安全**：每次 `tooMany()` 新建实例，避免实例属性串 key；`ArrayRateGuard` 的 key 隔离测试通过。
4. **Predis SET 参数**：vendor 的 `Predis\Command\Redis\SET::setArguments()` 将第 3 个参数之后的 `'EX', $ttl, 'NX'` 原样传递，当前 Predis 分支写法合法。
5. **Redis SET 选项**：phpredis/RedisCluster 的 `set($key, $value, ['nx','ex'=>$ttl])` 签名支持 options，主路径可用。
6. **超时单位**：阿里云 Darabonba `connectTimeout/readTimeout` 为毫秒，腾讯 `ReqTimeout` 为秒；当前换算正确。
7. **阿里云空 body**：`$body->code ?? ...` 在 `PHP 8.4` 下不会因 null/array 发 warning，会走失败结果；但仍建议按 SMS-04/SMS-16 的原则把“无法解析响应”显式抛错。
8. **`PhoneList` 正则**：`+` 可选、6-15 位数字，符合 E.164 风格；未强制中国号段是合理的国际化取舍。
9. **LogGateway 有状态**：类注释明确其为开发/测试网关，与生产网关“无请求态”的约定不同；只要 Manager 按请求创建即可。
10. **模板参数 JSON 编码失败**：`json_encode() === false` 会抛 `SmsApiException`，处理正确。

---

## 7. 修复路线

### 第一批（P0，安全/成本优先）

1. 新增号码 canonical 化能力，改造 `SmsVerifier::send()/verify()` 的所有 key 生成；补号码矩阵测试。
2. 扩展 `SmsStoreInterface`：`reserveAttempt()`、`deleteIfEquals()`（或一个 Lua `consumeCode`）；调整 `verify()` 顺序；补 Swoole 并发测试。
3. 修正 `VerifierInterface` 与 README 中“写法必须一致”的说明。

### 第二批（P1，功能正确性）

1. 修复 `TencentGateway::toE164()`，覆盖 `+`、`00`、已带国家码、`default_region='+86'`。
2. 修复 `SendResult::fromTencentBody()`：未请求号码不归入成功、缺行补失败、`ok` 全量校验。
3. `SmsVerifier` 构造期配置校验 + `RedisSmsStore::put()` 拒绝 `ttl<=0` + `DurationRateGuard` 参数校验。
4. `signName` 空串回落默认。
5. `PhoneList` canonical 去重 + `SmsMessage` 保留 `max`。
6. `CodeGenerator` 类型校验、`LogGateway` simulate 校验、`rateEnabled` 严格布尔解析。

### 第三批（P2，健壮性/可观测性/测试）

1. 发送顺序改为“先存码后发送，失败回滚”。
2. Redis 计数 Lua 化；测试替身 TTL 语义对齐。
3. `ApiExceptionFactory` 补 `getCode()`；新增 `SmsStorageException` 并包装底层异常；`finally` 清理异常不再覆盖原始异常。
4. `DurationRateGuard` prefix 语义修正；`RateLimitExceededException` 增加 `retryAfter/reason`。
5. `LogGateway` PII 策略、`sendCount` 语义；发送日志只由 Manager 统一记录。
6. Manager 日志补 channel/requestId/异常；群发日志限流；SDK 与号码非法异常原文不落日志。
7. 键前缀/HMAC/模板白名单等隐私加固。
8. 文档修正与测试补全（RedisSmsStore、并发、腾讯回执、配置边界）。

---

## 8. 回归测试清单

### 号码与限流

- [ ] `send('+86 138-0000-8000')` 后，`verify('+8613800008000', code)` 成功。
- [ ] 三种写法（`+86 138-0000-8000`、`+8613800008000`、`13800008000`）共用同一冷却键与限流键。
- [ ] 冷却期内任意写法重发都抛 `RateLimitExceededException`。
- [ ] `PhoneList` 三种写法去重后 count=1。
- [ ] `verify('')` / 非法号码抛 `InvalidPhoneException` 或按约定返回，不访问 Redis。

### 并发与原子性

- [ ] 两个协程用正确码并发校验：恰好一个 `ok`。
- [ ] 100 个协程并发错误码（max=5）：比较次数不超过 max，其余 locked。
- [ ] 并发校验与并发发送交错：旧码校验不删除新码/新冷却。
- [ ] 同一号码同格式并发发送：只发一条（现有用例回归）。

### 腾讯网关

- [ ] E.164 矩阵：裸 11 位、`+86`、`86`、`00`、`default_region='+86'`、国际号码。
- [ ] 回执缺行 → `ok=false`，缺失号码进入 failed。
- [ ] 回执含未请求号码 → 不进入 success。
- [ ] 回执缺 `PhoneNumber` → 不进入 success。
- [ ] 多参数模板顺序测试。

### 配置与边界

- [ ] `ttl=0/-1` 抛配置异常，不创建无过期键。
- [ ] `rate.max=0`、`period=0` 抛 `SmsException` 子类。
- [ ] `rate.key` 缺 `{phone}` 抛配置异常。
- [ ] `enabled='false'/'0'` 正确关闭限流；非法布尔字符串抛错。
- [ ] `CodeGenerator` 类型 `number/alnum/numeric/ALNUM/未知值` 的行为符合文档。
- [ ] `LogGateway simulate=success/failure/partial/带空格/未知值`。
- [ ] `signName=''` / 空白 / 覆盖值。
- [ ] `SmsMessage::to()` 保留 max。
- [ ] `ApiExceptionFactory` 从 `getErrorCode()`、public `code`、`getCode()` 三个来源取码。
- [ ] `DurationRateGuard` 默认不加 `sms:rg:`，自定义 prefix 仍生效。
- [ ] `LogGateway` 经 Manager 发送时只产生一条日志。
- [ ] 发送成功后 trace/冷却写入失败仍返回 `ok`，且冷却不被释放。
- [ ] 配置键带前后空白（顶层与 `rate_limit`）抛 `InvalidConfigException`。
- [ ] 失败日志不含 `SmsApiException` / `InvalidPhoneException` 的原文。
- [ ] 窗口限流命中的 `retryAfter` 为真实剩余窗口秒数，查询失败退回 `period`。

### 现有回归

- [ ] `tests/Sms` 全部 176 个用例通过。
- [ ] `tests/Sms/SmsVerifierTest.php` 的冷却、限流、锁定、模板隔离用例通过。
- [ ] `tests/Sms/SmsFixTest.php` 的 payload、SDK 异常、DurationRateGuard 键隔离用例通过。

---

## 9. 附录

### 9.1 运行环境

```text
PHP 8.4.14 (cli)
Swoole 6.1.7 (ZTS)
PHPUnit 11.5.57
```

### 9.2 复现方式

审查期间的 `.sms-repro.php` 与 `/tmp/sms-audit*.php` 均为一次性脚本，记录的是修复前行为，已删除。正式回归统一使用：

```bash
./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Sms
```

### 9.3 关键实测结果汇总

| 编号 | 场景 | 结果 |
|---|---|---|
| A1-A5 | 带空格号码发送/校验 | 键含原始空格；归一化号码校验 absent |
| B1-B2 | ttl=0 | code/trace TTL 为 0（生产 Redis 无过期） |
| C1-C2 | `type='numeric'` | 静默按数字生成 |
| D1 | `simulate=' failure '` | 静默成功 |
| E1-E2 | 腾讯 E.164 | `+868613800008000`、`++8613800008000` |
| F1-F4 | 腾讯回执缺行 | ok=true，缺失号码不在任何列表 |
| G1 | 空模板表 | `login_code` 原样返回 |
| H1-H2 | 限流 max=0 | 抛 `Swoolefy\Library\Exception\RateLimitException`，非 SmsException |
| I1 | `SmsMessage::to()` | max=2 的实例接受 3 个号码 |
| J1 | 多写法去重 | 3 个号码全部保留 |
| K1 | `verify('')` | 返回 absent，不校验号码 |
| L1-L2 | 发送失败 | 限流计数仍为 1；冷却释放 |
| M1 | LogGateway 40 次发送 | sendCount=32（保留上限） |
| N1 | 普通异常 code | providerCode=null |
| P1-P2 | 腾讯回执含未知号码 | 未知号码进入 success，ok=true |
| Q1 | signName='' | 请求签名为空 |
| R1 | increment 第二次 | Array 替身 TTL 被重置为 1（生产只在首次设置） |
| S1 | 并发正确码 | `["ok","ok"]` |
| 2.1-2.2 | 20 并发错误码 | 4 mismatch / 16 locked，attempts=20 |
| 1.1-1.4 | 多写法冷却 | 三次都成功；带空格写法不被冷却拦截 |

### 9.4 vendor 语义依据

- `src/RateLimit/DurationLimiter.php`：`isLimit()` 在 `empty($limitNum)` / `empty($windowSizeTime)` 时抛 `Swoolefy\Library\Exception\RateLimitException`；未超限时即写入一次消费。
- `vendor/tencentcloud/common/.../TencentCloudSDKException.php`：提供 `getErrorCode()` / `getRequestId()`，`code` 固定为 0。
- `vendor/alibabacloud/openapi-core/src/Exceptions/AlibabaCloudException.php`：提供 `getRequestId()`；父类 `TeaError` 有 public `$code` / `$data`。
- `vendor/predis/predis/src/Command/Redis/SET.php`：`setArguments()` 保留第 3 个参数之后的 `EX/NX` 参数。
- `php -r` 反射确认 `\Redis` 没有 `__toString()`；`RedisCluster::set()` 第三个参数为 `mixed $options`。

---

## 10. P0 修复记录（2026-10-20）

**SMS-01 号码键归一化**

- `PhoneList::canonical()`：去分隔符、`00` → `+`，大陆手机号的 `+86` / `86` / 裸 11 位统一为 `+86`。
- `SmsVerifier::send()`：消息使用 `PhoneList` 归一后的线上号码；冷却、限流、验证码、attempts、trace 键统一使用 canonical 号码。
- `SmsVerifier::verify()`：先经 `PhoneList` 校验并 canonical 化；空串 / 非法号码在访问存储前抛 `InvalidPhoneException`。

**SMS-02 原子校验**

- `SmsStoreInterface` 新增 `verifyCode()`：读取、比较、消费、失败计数、锁定和成功清理必须原子完成。
- `RedisSmsStore`：单个 Lua 脚本完成上述操作，KEYS 为 code / attempts / trace / cooldown；Predis 与 phpredis / RedisCluster 分别适配 eval 参数顺序。
- `ArraySmsStore`：内存实现同语义；`increment()` 改为仅首次创建时设置 TTL，与生产 Redis 对齐。

**验证**

- `tests/Sms`：67 tests / 189 assertions 全绿。
- 新增用例覆盖：多写法共用冷却/限流/验证码键、非法号码校验、并发正确码仅一个 `ok`、并发错误码 `attempts` 不超过 `max_verify`。
- 本机 Redis 实测（phpredis 与 Predis 两个驱动）：absent → 4 次 mismatch → 第 5 次 locked → 正确码 ok 并清空四类键；attempts=5、TTL 正常。
- 修复前的 `.sms-repro.php` 仅记录旧行为，不再代表当前实现；P0 回归以 `tests/Sms` 为准。

---

**结论**：P0 两个安全问题已修复并通过回归。其余 P1/P2（腾讯 E.164、回执映射、配置边界、日志与 PII 等）仍按第 7 节路线待处理。
