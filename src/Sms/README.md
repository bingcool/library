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
            'resend_interval' => 60,            // 同号码重发间隔(秒)
            'rate_limit'      => [
                'enabled' => true,
                'key'     => 'sms:rl:{phone}',
                'max'     => 5,
                'period'  => 3600,
            ],
        ],
    ],
];
```

`Config/component/sms.php`（组件由框架 `loadComponents()` 自动合并）：

```php
<?php
use Swoolefy\Core\Application;
use Swoolefy\Library\Sms\SmsManager;
use Swoolefy\Library\Sms\Verification\{RedisSmsStore, DurationRateGuard, SmsVerifier};

return [
    'sms' => static function (): SmsManager {
        $c = include APP_PATH . '/Config/sms.php';
        return new SmsManager($c['sms'], Application::getApp()->get('log'));
    },

    // 验证码服务：注入 sms + redis 存储 + redis 限流
    'sms_verifier' => static function (): SmsVerifier {
        $app = Application::getApp();
        $c   = include APP_PATH . '/Config/sms.php';
        $redis = $app->get('redis')->getObject();   // RedisConnection（协程连接池）

        return new SmsVerifier(
            $app->get('sms'),
            new RedisSmsStore($redis),
            new DurationRateGuard($redis),
            $c['sms']['verification'],
        );
    },
];
```

> 每请求 / 协程通过 DI `get` 取 Manager 即可。勿在 Worker 进程内 `static` 缓存 Manager（配置 / env 变更后不刷新）。

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
    return $this->returnJson(['code' => 1, 'msg' => $e->getMessage()]);
}

if (!$verifier->verify($phone, $inputCode)) {
    return $this->returnJson(['code' => 1, 'msg' => '验证码错误或已失效']);
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

脱机单测推荐用 `Testing/` 替身：`FakeSmsGateway`（脚本化结果队列）、`ArraySmsStore`、`ArrayRateGuard`，无需 Redis / 厂商 SDK。

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
- Gateway 无请求态（只含 AK/SK/region），可被 Manager 同请求内安全复用。
- 限流每次调用新建 `DurationLimiter`，避免协程共用一个实例时串 key。重发冷却先于扣配额检查。
- 验证码按 `模板 + 号码` 存储，登录码和注册码互不覆盖。冷却和小时窗口仍按号码。
- `timeout`（秒，默认 5）写入阿里云连接/读取超时和腾讯云 `ReqTimeout`。
- `LogGateway` 只保留最近 32 条且模板参数打码，不在内存里堆积验证码明文。
- 批量群发交框架 `Job` 分片 + 重试 + 死信，组件只提供同步 `send()`。
- 安全底线：`access_secret` / `secret_key` 永不进日志 / 结果 / 异常；号码一律 `PhoneMask::mask()` → `138****8000`；验证码明文不落日志；校验用 `hash_equals` 常量时间比对。

---

## 测试

```bash
./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Sms
```

覆盖：值对象（号码归一 / 结果映射）、参数映射（阿里 vs 腾讯）、配置解析、策略路由、厂商 Client 构造、Manager 编排与脱敏日志、SmsVerifier 发送 / 重发冷却 / 窗口限流 / 校验 / 防暴力锁定。真实发送用例默认不连外网，用 `log` 网关与替身模拟成功 / 失败 / 部分失败。
