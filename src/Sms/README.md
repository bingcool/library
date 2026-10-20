# Sms

多厂商短信统一门面（实现于 **bingcool/library**）：**策略模式 + 配置化**，阿里云 / 腾讯云网关可插拔，验证码服务复用库内 `RateLimit` / `Redis`。设计见 [docs/library-6.x-Sms短信技术方案.md](../../docs/library-6.x-Sms短信技术方案.md)。

## 能力

| Gateway | 厂商 SDK | 逐号回执 | 说明 |
|---------|----------|----------|------|
| aliyun | `alibabacloud/dysmsapi-20170525:~4.6.0`（本库依赖） | 整体 | `Code=OK` 判定成功 |
| tencent | `tencentcloud/sms:~3.0`（本库依赖） | ✅ | `TemplateId` + `SendStatusSet` 逐号码；单次最多 200 个号码 |
| log | 无 | 可控模拟 | 本地 / 联调 / 单测：`simulate=success\|failure\|partial` |
| 其他 | 按需安装 | 由实现决定 | `extendDriver()` 注册，例如以后的百度 |

范式与 `Oauth` / `FileStorageSystem` 一致：`Manager + Factory + Config::fromArray + Contracts + ValueObject + Exception`。内置厂商直接构造官方 Client；可选新厂商用 `extendDriver()`，缺包时用 `SdkGuard`。

---

## 在 Swoolefy 中使用

### 1. 准备配置与组件

`Config/sms.php`：

```php
<?php
return [
    'sms' => [
        'default_gateway' => 'aliyun',
        // Config/component/cache.php 里的组件名，验证码存储和限流都用它
        'redis_component' => 'cache',
        // 号码默认地区：CN | HK | MO | TW（也接受 86/852/853/886）。
        // 只影响「裸号码」的解释；境外号码建议显式写 + 或 00 前缀。
        'default_region' => 'CN',
        'gateways' => [
            'aliyun' => [
                'gateway'       => 'aliyun',
                'access_key'    => env('SMS_ALI_KEY'),
                'access_secret' => env('SMS_ALI_SECRET'),   // 绝不打日志
                'region'        => 'cn-hangzhou',
                'sign_name'     => '示例签名',
                'templates'     => ['login_code' => 'SMS_100001', 'order_notice' => 'SMS_100002'],
            ],
            'tencent' => [
                'gateway'    => 'tencent',
                'secret_id'  => env('SMS_TX_ID'),
                'secret_key' => env('SMS_TX_KEY'),
                'sdk_app_id' => env('SMS_TX_SDK_APP_ID'),
                'region'     => 'ap-guangzhou',
                'sign_name'  => '示例签名',
                'templates'  => ['login_code' => '100001', 'order_notice' => '100002'],
                // TemplateParamSet 按 {1}{2}… 占位顺序发送；配置后按键名取值，缺失/多余参数直接报错
                'param_order' => ['code'],
            ],
            // 联调 / 单测：不真发，可模拟结果
            'log' => ['gateway' => 'log'],
        ],
        // 验证码策略（复用库内 Redis + RateLimit）
        'verification' => [
            'length'          => 6,
            'type'            => 'number',      // number|alnum
            'ttl'             => 300,
            'max_verify'      => 5,             // 单码最大校验次数，达上限即失效（防暴力）
            'resend_interval' => 60,            // 同一场景、同一号码的重发间隔(秒)
            // 可选：应用/环境命名空间，避免同一 Redis 实例上的多应用键冲突
            // 'key_prefix' => 'myapp:prod:',
            // 可选：>=16 字符的密钥。启用后手机号与验证码都以 HMAC 入键/入值，Redis 不存 PII 与 OTP 明文
            // 'hash_key' => env('SMS_HASH_KEY'),
            // 可选：模板白名单；未配置时只做 [A-Za-z0-9_.-]{1,64} 字符校验
            // 'allowed_templates' => ['login_code', 'register_code'],
            'rate_limit'      => [
                'enabled' => true,
                'key'     => 'sms:rl:{template}:{phone}',
                'max'     => 5,
                'period'  => 3600,
            ],
        ],
    ],
];
```

验证码配置在构造期校验：`ttl >= 1`、`max_verify >= 1`、`resend_interval >= 0`；启用限流时 `max/period >= 1` 且 `rate_limit.key` 必须包含 `{phone}`。`rate_limit.enabled` 按严格布尔解析（`'false'` 会真正关闭），非法值抛 `InvalidConfigException`。配置键名不允许前后空白（如 `' resend_interval'`），否则直接抛 `InvalidConfigException`，避免 typo 静默落回默认值。模板名同样在构造期与每次调用时校验；配置了 `allowed_templates` 后只放行白名单。

隐私加固（可选）：`key_prefix` 隔离应用/环境；`hash_key` 用 HMAC-SHA256 处理手机号与验证码，Redis 键值中不再出现完整号码和 OTP 明文。启用 `hash_key` 会使已存在的旧验证码失效。

`Config/component/sms.php`（组件由框架 `loadComponents()` 自动合并）：

```php
<?php
use Swoolefy\Core\Application;
use Swoolefy\Library\Sms\Config\SmsConfig;
use Swoolefy\Library\Sms\SmsManager;
use Swoolefy\Library\Sms\Verification\{RedisSmsStore, DurationRateGuard, SmsVerifier};

return [
    'sms' => static function (): SmsManager {
        $c = include APP_PATH . '/Config/sms.php';
        return new SmsManager($c['sms'], Application::getApp()->get('log'));
    },

    // 验证码服务：存储和限流都用 sms.php 的 redis_component（cache.php 里的组件）
    'sms_verifier' => static function (): SmsVerifier {
        $config = SmsConfig::fromArray(include APP_PATH . '/Config/sms.php');
        $redis = Application::getApp()->get($config->redisComponent());
        if (is_object($redis) && method_exists($redis, 'getObject')) {
            $redis = $redis->getObject();
        }

        return new SmsVerifier(
            Application::getApp()->get('sms'),
            new RedisSmsStore($redis),
            new DurationRateGuard($redis),
            $config->verification(),
        );
    },
];
```

> 每请求 / 协程通过 DI `get` 取 Manager 即可。勿在 Worker 进程内 `static` 缓存 Manager（配置 / env 变更后不刷新）。

### 1.1 号码与地区（大陆 / 香港 / 澳门 / 台湾）

`PhoneNormalizer` 把四地号码统一为 E.164，验证码键、冷却、限流和厂商请求都以此为准。

| 地区 | 区号 | 可接受写法（示例） | 归一化结果 |
|---|---|---|---|
| 中国大陆 | +86 | `13800008000`、`8613800008000`、`008613800008000`、`+86 138-0000-8000` | `+8613800008000` |
| 中国香港 | +852 | `+85261234567`、`85261234567`、`0085261234567`；裸 `61234567` + `default_region=HK` | `+85261234567` |
| 中国澳门 | +853 | `+85366123456`、`85366123456`、`0085366123456`；裸 `66123456` + `default_region=MO` | `+85366123456` |
| 中国台湾 | +886 | `+886912345678`、`886912345678`、`00886912345678`、本地 `0912345678` | `+886912345678` |

- 港、澳本地号码都是 8 位且号段重叠，裸 8 位必须由 `default_region` 指定 HK 或 MO；默认 `CN` 时会直接抛 `InvalidPhoneException`，避免猜错地区。
- 四地之外的号码必须带 `+` 或 `00` 国际前缀；裸号码按默认地区解释（`+1`/`+44` 等请显式书写）。
- 同一号码的不同写法共用一套验证码、冷却和限流键；`send()` / `verify()` 混用写法不会绕过限制。
- 厂商差异由网关消化：腾讯云发 E.164（带 `+`），且**单次请求必须全为大陆号码或全为境外号码**；阿里云国内发 11 位、国际/港澳台发「国际区号+号码」（不带 `+`）。
- 港澳台属于境外短信，腾讯云/阿里云需使用已审核的**国际/港澳台模板与签名**；建议按地区单独配置 gateway 或模板别名。

### 2. 直接发送（策略选择）

```php
use Swoolefy\Core\Application;
use Swoolefy\Library\Sms\SmsMessage;

$sms = Application::getApp()->get('sms');

// 默认通道
$r1 = $sms->send(SmsMessage::make('13800008000', 'order_notice', ['order' => '10086']));

// 显式走腾讯云通道（多号码）
$r2 = $sms->sendVia('tencent', SmsMessage::make(
    ['13800008000', '13900009000'], 'login_code', ['code' => '8888']
));

if (!$r2->ok) {
    // $r2->successPhones / $r2->failedPhones / $r2->requestId（腾讯支持部分失败）
}
```

### 3. 登录验证码（编排 + 限流 + 防暴力一体）

```php
$verifier = Application::getApp()->get('sms_verifier');

try {
    $verifier->send($phone, 'login_code');   // 命中窗口限流 / 重发间隔抛 RateLimitExceededException
} catch (\Swoolefy\Library\Sms\Exception\RateLimitExceededException $e) {
    // reason: cooldown（重发间隔）| window（小时窗口）
    // retryAfter: 实际剩余秒数（冷却取剩余 TTL，窗口取最早一次消费滑出窗口的时间），可直接写入 HTTP Retry-After
    return $this->returnJson([
        'code' => 1,
        'msg' => $e->getMessage(),
        'reason' => $e->reason(),
        'retry_after' => $e->retryAfter(),
    ]);
}

$result = $verifier->verify($phone, $inputCode, 'login_code');
if (!$result->ok) {
    // status:
    //   mismatch  输错，remaining 为还可尝试次数
    //   expired   发过，验证码已过期
    //   absent    该场景没发过
    //   locked    错误次数用尽，码已作废
    return $this->returnJson(['code' => 1, 'msg' => $result->message, 'status' => $result->status]);
}
```

### 4. 联调 / 单测走 log 通道（模拟成功 / 失败 / 部分失败）

```php
use Swoolefy\Library\Sms\Gateway\LogGateway;
use Swoolefy\Library\Sms\SmsMessage;

// 直接成功
$ok = (new LogGateway(['gateway' => 'log']))->send(SmsMessage::make('13800008000', 'login_code'));

// 模拟厂商失败
$fail = (new LogGateway(['gateway' => 'log', 'simulate' => 'failure', 'fail_code' => 'isv.AMOUNT_NOT_ENOUGH']))
    ->send(SmsMessage::make('13800008000', 'login_code'));

// 模拟部分失败（奇数位号码失败）
$partial = (new LogGateway(['gateway' => 'log', 'simulate' => 'partial']))
    ->send(SmsMessage::make(['13800008000', '13900009000', '13700007000'], 'login_code'));
```

脱机单测推荐用 `Testing/` 替身：`FakeSmsGateway`（脚本化结果队列）、`ArraySmsStore`（支持 `advance()` 虚拟时钟推进 TTL）、`ArrayRateGuard`，无需 Redis / 厂商 SDK。替身与生产语义对齐：模板别名走同一解析规则，非法限流参数同样抛异常。

`LogGateway` 本身不写日志，发送日志统一由 `SmsManager` 记录。快照默认只保留打码号码（`138****8000`）和 `***` 参数；本地联调确实需要原始号码时显式配置 `'keep_phones' => true`。`sendCount()` 返回累计发送次数，`sentMessages()` 只保留最近 32 条。

### 5. 常用 API 速查

```php
$sms = Application::getApp()->get('sms');

$sms->gateway(?string $name = null): SmsGatewayInterface;   // 按名解析并缓存
$sms->send(SmsMessage $message): SendResult;                // 默认通道
$sms->sendVia(?string $name, SmsMessage $message): SendResult;
$sms->extendDriver(string $driver, callable $creator): void; // 注册新厂商 / 测试替身

// 结果对象（厂商无关）
$result->ok;                 // 是否全部成功
$result->isPartial();        // 有成功也有失败
$result->successPhones;      // list<string>
$result->failedPhones;       // list<array{phone, code?, reason?}>
$result->bizId / $result->requestId / $result->code / $result->message;

// 消息 DTO（可序列化，便于投框架 Job 异步群发）
SmsMessage::make(string|array $phones, string $template, array $params = [], ?string $signName = null);
```

新增厂商（如百度）不用改阿里云、腾讯云代码：

1. 实现 `SmsGatewayInterface`。
2. 调用 `SmsManager::extendDriver('baidu', $creator)`，或在 `SmsFactory` 的 `match` 里加一行。
3. 该厂商 SDK 若未写入本库 `require`、由使用方按需安装，在网关构造函数里调用 `SdkGuard::requireClass()`。缺包时抛 `MissingSdkException`。

```php
use Swoolefy\Library\Sms\Support\SdkGuard;

SdkGuard::requireClass(
    \Vendor\Baidu\SmsClient::class,
    'vendor/baidu-sms',
    'baidu'
);
```

---

## Composer SDK

`alibabacloud/dysmsapi-20170525` 与 `tencentcloud/sms` 已是本库依赖，网关直接构造官方 Client。`log` 网关和 `Testing` 替身不依赖它们。尚未成为硬依赖的新厂商走上面的 `SdkGuard`。

---

## 协程与安全要点

- HTTP 走 Guzzle/curl，Swoole `SWOOLE_HOOK_ALL` 下自动协程化，`send()` 不阻塞 Worker。
- 生产 Gateway 无请求态（只含 AK/SK/region），可被 Manager 同请求内安全复用；`LogGateway` 是唯一例外（保留有上限的发送快照），不要跨协程共享。
- 限流每次调用新建 `DurationLimiter`，避免协程共用一个实例时串 key。重发冷却用 SET NX 先占位，再扣配额。发送失败会放开占位。`RateLimitExceededException::retryAfter()` 返回真实剩余秒数：冷却取剩余 TTL，窗口取最早一次消费滑出窗口的时间（查询失败退回 `period`）。
- 自定义 `SmsStoreInterface` 实现必须提供 `verifyCode()` 的原子语义；生产用 `RedisSmsStore`（Lua），联调 / 单测用 `ArraySmsStore`（无 IO，等价语义）。
- 验证码、重发冷却和小时限额都按 `模板 + canonical E.164 号码`。大陆 `+86` / `86` / 裸 11 位 / `00` 前缀，以及香港 `+852` / 澳门 `+853` / 台湾 `+886` 与本地写法都归一为同一个键，换写法无法绕过冷却或限流。裸 8 位港澳号码依赖 `default_region`，验证码服务与网关会用同一份顶层配置。登录码冷却不会挡住注册码，两边的小时次数也分开计。校验成功只清该场景的冷却。
- `timeout`（秒，默认 5）写入阿里云连接/读取超时和腾讯云 `ReqTimeout`。
- `LogGateway` 只保留最近 32 条，号码与模板参数均打码，不在内存里堆积 PII / 验证码明文。
- 每次发送只由 Manager 记录一条日志，LogGateway 不重复写。日志失败不影响发送主流程；网关抛异常时记录 `channel` / `exception` / `provider_code` / `request_id` / `duration_ms`，群发号码最多记前 5 个并标记 `phones_truncated`。厂商 SDK 异常与号码非法异常的原文可能包含请求细节或完整号码，不写入日志。
- 批量群发交框架 `Job` 分片 + 重试 + 死信，组件只提供同步 `send()`。
- 安全底线：`access_secret` / `secret_key` 永不进日志 / 结果 / 异常；号码一律 `PhoneMask::mask()` → `138****8000`；验证码明文不落日志。验证码校验由 `RedisSmsStore` 的单个 Lua 脚本原子完成，并发下同一码至多成功一次，失败次数不会突破 `max_verify`。

---

## 测试

```bash
./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Sms
```

覆盖：值对象（号码归一 / 结果映射）、四地区 E.164 归一化（大陆 / 香港 / 澳门 / 台湾）、参数映射（阿里 vs 腾讯，含 `param_order`）、配置解析、策略路由、厂商 Client 构造与请求号码格式、Manager 编排与脱敏日志、SmsVerifier 先落码后发送 / 失败回滚 / 冷却与窗口剩余时间 / 校验 / 防暴力锁定、存储 TTL 原子性与 HMAC 隐私加固。真实发送用例默认不连外网，用 `log` 网关与替身模拟成功 / 失败 / 部分失败。
