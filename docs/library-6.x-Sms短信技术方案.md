# Library 6.x Sms 短信网关技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 命名空间 | `Swoolefy\Library\Sms` |
| 模块目录 | `src/Sms/` |
| 优先级 | P1 |
| 定位 | 多厂商短信统一门面：**策略模式 + 配置化**，阿里云 / 腾讯云网关可插拔；验证码服务复用库内 RateLimit / Redis |
| 网关选型 | 阿里云 `alibabacloud/dysmsapi-20170525:~4.6.0`、腾讯云 `tencentcloud/sms:~3.0` |

> 用户明确要求：**Sms 网关配置化、策略模式等**，风格对齐现有 `Oauth` 多厂商门面。本方案遵循 library 现有 `Manager + Factory + 配置 fromArray + Contracts 能力接口 + Exception` 范式。阿里云、腾讯云 SDK 已是本库依赖，网关直接构造官方类。以后新增且不打进 `require` 的厂商，用 `extend()` 注册，并在该网关内用 `SdkGuard` 检测可选 SDK。

---

## 2. 与 swoolefy 框架 / 现有库模块的职责边界

| 能力 | 归属 | 说明 |
|---|---|---|
| 各厂商签名、请求、响应解析 | **library** `Sms`（策略网关） | 本方案核心 |
| 多厂商配置 | **library** 解析 `Config/sms.php` | 配置化 |
| DI 实例化 | **框架** `Application::getApp()->get('sms')` | 组件闭包注册 |
| 限流 / 防刷 | **library** `RateLimit`（复用，非重写） | 验证码发送前置流控 |
| 验证码缓存 / 计数 | **library** `Redis` / `Cache` | 存储 code + 发送次数 |
| 日志 | **框架** `log`（PSR-3 注入） | 号码脱敏后记录 |
| 异步群发 / 失败重试投递 | **框架** `Job` | 组件提供同步 `send()`，编排交框架 |

**非目标：**

- 不重写各家签名算法细节，委托官方 SDK
- 不做号码归属地查询 / 智能路由；仅对大陆、香港、澳门、台湾做 E.164 归一化
- 不在组件内自造限流器，复用 `RateLimit`
- 不建 SQL 表（验证码走 Redis，与框架 Job「零建表」风格一致）

---

## 3. 设计目标与原则

1. **策略模式**：`SmsGatewayInterface` 为抽象策略；`AliyunGateway` / `TencentGateway` / `LogGateway` 为具体策略；运行时按配置或显式选择切换，互不侵入。
2. **配置驱动 + 多通道**：`default_gateway` + 多个 gateway 块（各含签名所需 AK/SK、region、SignName、模板映射）。
3. **网关无关的领域对象**：`SmsMessage`（手机号 + 模板 ID + 参数）与 `SendResult` 统一，屏蔽厂商差异；模板参数用关联数组，由各 Gateway 适配成厂商格式。
4. **可扩展**：新增厂商 = 新增一个实现 `SmsGatewayInterface` 的类 + Factory 注册一行，符合开闭原则。
5. **协程安全**：Manager 无状态、Gateway 不可变；HTTP 走 Guzzle/Swoole hook；验证码服务依赖 Redis 协程连接池。
6. **安全**：AK/SK 仅来自配置/env；日志手机号脱敏（`138****8000`）；验证码明文不落日志。

### 3.1 策略模式类图

```text
                 SmsGatewayInterface（策略抽象）
                          ▲
        ┌─────────────────┼─────────────────┬───────────────┐
        │                 │                 │               │
  AliyunGateway     TencentGateway     YunpianGateway*   LogGateway
  (dysmsapi)        (tencentcloud)      (预留)          (测试假发)

   SmsManager ──► SmsFactory.make(name, config) ──► match(gateway) ──► 具体策略
        │
        └── 缓存 Gateway 实例（无请求态），按 provider 名复用
```

`*` 预留网关仅示意扩展点，本方案交付 Aliyun / Tencent / Log 三个。

---

## 4. Composer 依赖选型

```jsonc
{
    "require": {
        "alibabacloud/dysmsapi-20170525": "~4.6.0",
        "tencentcloud/sms": "~3.0"
    }
}
```

| 依赖 | 用途 | 检测入口类 |
|---|---|---|
| `alibabacloud/dysmsapi-20170525` | 阿里云短信发送 | `AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi` |
| `tencentcloud/sms` | 腾讯云短信发送 | `TencentCloud\Sms\V20210111\SmsClient` |

> 阿里云、腾讯云随本库安装，网关内直接 `new` 官方 Client，不再做 autoload 探测。以后的可选厂商（如百度）不强制写入 `require`：实现 `SmsGatewayInterface` 后 `extend()` 注册，构造时用 `SdkGuard::requireClass()`，缺包抛 `MissingSdkException`。

---

## 5. 目录结构

```text
src/Sms/
├── SmsManager.php                 # DI 入口；按 name 解析并缓存 Gateway（策略上下文）
├── SmsFactory.php                 # match(gateway) 装配具体策略
├── SmsConfig.php                  # 配置只读访问器（fromArray）
├── SmsMessage.php                 # 领域 DTO：phones + template + params + signature
├── Contracts/
│   ├── SmsGatewayInterface.php     # 策略抽象：send(SmsMessage): SendResult
│   └── VerifierInterface.php        # 验证码生成/校验抽象
├── Gateway/
│   ├── AliyunGateway.php           # 阿里云策略
│   ├── TencentGateway.php          # 腾讯云策略
│   └── LogGateway.php              # 测试假发（不真发，内存记录）
├── Verification/
│   ├── CodeGenerator.php            # 数字/字母验证码（可注入随机源）
│   ├── CodeStore.php                # Redis 存 code + 失败计数 + TTL
│   └── SmsVerifier.php               # 发送+校验编排，接入 RateLimit
├── ValueObject/
│   ├── SendResult.php               # ok/phones成功/失败/bizId/requestId/message
│   └── PhoneList.php                # 归一化号码集合 + 去重 + 校验
├── Support/
│   ├── SdkGuard.php
│   ├── PhoneMask.php                # 日志脱敏 138****8000
│   ├── PhoneNormalizer.php          # 大陆/港澳台 → E.164（默认地区、00/+ 前缀）
│   └── TemplateParamMapper.php       # 关联数组 → 厂商参数格式
└── Exception/
    ├── SmsException.php            # base
    ├── InvalidProviderException.php  # 网关未配置（对齐 Oauth/FileStorage 同名）
    ├── InvalidConfigException.php    # 配置值非法（如 default_region）
    ├── MissingSdkException.php
    ├── SmsApiException.php          # 厂商返回业务错误
    ├── InvalidPhoneException.php
    └── RateLimitExceededException.php
```

---

## 6. 配置设计

### 6.1 `Config/sms.php`

```php
<?php
// APP_PATH/Config/sms.php
return [
    'sms' => [
        'default_gateway' => 'aliyun',
        // Config/component/cache.php 里的组件名，验证码存储和限流都用它
        'redis_component' => 'cache',
        // 裸号码默认地区：CN | HK | MO | TW（兼容 86/852/853/886）
        'default_region' => 'CN',

        // 逻辑通道：业务用名字，不用关心厂商
        'gateways' => [
            'aliyun' => [
                'gateway'       => 'aliyun',
                'access_key'    => env('SMS_ALI_KEY'),
                'access_secret' => env('SMS_ALI_SECRET'),   // 绝不打日志
                'region'        => 'cn-hangzhou',
                'sign_name'     => '示例签名',
                // 模板别名 → 厂商真实 TemplateCode
                'templates' => [
                    'login_code'   => 'SMS_100001',
                    'order_notice' => 'SMS_100002',
                ],
            ],
            'tencent' => [
                'gateway'     => 'tencent',
                'secret_id'   => env('SMS_TX_ID'),
                'secret_key'  => env('SMS_TX_KEY'),
                'sdk_app_id'  => env('SMS_TX_SDK_APP_ID'),
                'region'      => 'ap-guangzhou',
                'sign_name'   => '示例签名',
                'templates' => [
                    'login_code'   => '100001',
                    'order_notice' => '100002',
                ],
            ],
            // 单测/联调：不真发
            'log' => [
                'gateway' => 'log',
            ],
        ],

        // 验证码策略（复用 RateLimit + Redis）
        'verification' => [
            'length'      => 6,
            'type'        => 'number',        // number|alnum
            'ttl'         => 300,             // code 有效期(秒)
            'max_verify'  => 5,               // 单码最大校验次数
            'resend_interval' => 60,           // 同一场景、同一号码的重发间隔(秒)
            // 依赖库内 RateLimit 组件的 key 前缀与窗口配置
            'rate_limit'  => [
                'enabled' => true,
                'key'     => 'sms:rl:{template}:{phone}',
                'max'     => 5,               // 窗口内最多发送条数
                'period'  => 3600,            // 秒
            ],
        ],
    ],
];
```

### 6.2 `SmsConfig`

对齐 `OauthConfig`/`FileStorageConfig`：`fromArray()` 兼容外层 `sms` 包裹；`defaultGateway()`、`redisComponent()`、`defaultRegion()`、`hasGateway()`、`gateway()`、`verification()`。`redisComponent()` 默认 `cache`，对应 `Config/component/cache.php` 的组件键；`defaultRegion()` 默认 `CN`，`verification()` 未单独配置时继承顶层值。

### 6.2.1 号码归一化

`Support\PhoneNormalizer` 统一产出 E.164：

- 大陆：裸 11 位、`86`、`+86`、`00 86`；手机号 `1[3-9]xxxxxxxxx`
- 香港 / 澳门：`+852` / `+853`、`852` / `853`、`00` 前缀；本地 8 位。两地号段重叠，裸 8 位必须由 `default_region` 指定 HK / MO
- 台湾：`+886` / `886` / `00 886`；本地 `09xxxxxxxx` 自动去掉主干 0，E.164 为 `+8869xxxxxxxx`
- 四地之外：必须带 `+` / `00`，原样保留为 E.164

腾讯云网关统一发送 E.164，并提前拦截「大陆 + 境外」混发（厂商要求全境内或全境外）；阿里云网关国内发 11 位、国际/港澳台发「国际区号+号码」（不带 `+`）。验证码键、冷却与限流均使用同一 canonical E.164。

### 6.3 DI 组件 `Config/component/sms.php`

```php
<?php
use Swoolefy\Library\Sms\SmsManager;

return [
    'sms' => static function (): SmsManager {
        $c = include APP_PATH . '/Config/sms.php';
        return new SmsManager($c['sms'], Application::getApp()->get('log'));
    },

    // 验证码服务：存储和限流都用 redis_component（cache.php 组件名）
    'sms_verifier' => static function (): \Swoolefy\Library\Sms\Verification\SmsVerifier {
        $config = \Swoolefy\Library\Sms\Config\SmsConfig::fromArray(include APP_PATH . '/Config/sms.php');
        $redis = Application::getApp()->get($config->redisComponent());
        if (is_object($redis) && method_exists($redis, 'getObject')) {
            $redis = $redis->getObject();
        }

        return new \Swoolefy\Library\Sms\Verification\SmsVerifier(
            Application::getApp()->get('sms'),
            new \Swoolefy\Library\Sms\Verification\RedisSmsStore($redis),
            new \Swoolefy\Library\Sms\Verification\DurationRateGuard($redis),
            $config->verification(),
        );
    },
];
```

---

## 7. 核心类设计

### 7.1 策略抽象 `SmsGatewayInterface`

```php
interface SmsGatewayInterface
{
    /** 发送；内部完成厂商签名、请求、响应→SendResult 归一 */
    public function send(SmsMessage $message): SendResult;

    /** 该网关支持的模板别名（供校验/提示） */
    public function templateCode(string $alias): ?string;
}
```

### 7.2 `SmsManager`（策略上下文，缓存 Gateway）

```php
final class SmsManager
{
    /** @var array<string, SmsGatewayInterface> */
    private array $gateways = [];

    public function __construct(
        private array|SmsConfig $config,
        private ?\Psr\Log\LoggerInterface $logger = null,
        ?SmsFactory $factory = null,
    ) {
        if (is_array($this->config)) {
            $this->config = SmsConfig::fromArray($this->config);
        }
        $this->factory = $factory ?? new SmsFactory();
    }

    /** 按通道名取策略；null 用 default_gateway。同请求内复用同实例 */
    public function gateway(?string $name = null): SmsGatewayInterface;

    /** 直发默认通道 */
    public function send(SmsMessage $message): SendResult;
}
```

对齐 `OauthManager::provider()` / `FileStorageManager::disk()`：缓存策略实例但**不缓存任何请求态/凭据刷新态**。

### 7.3 `SmsFactory`（策略路由）

```php
final class SmsFactory
{
    public function make(string $name, array $config): SmsGatewayInterface
    {
        return match ((string) ($config['gateway'] ?? '')) {
            'aliyun'  => new AliyunGateway($config),
            'tencent' => new TencentGateway($config),
            'log'     => new LogGateway($config),
            default   => throw new InvalidProviderException("Unknown sms gateway [{$name}]"),
        };
    }
}
```

### 7.4 `SmsMessage`（厂商无关 DTO）

```php
final class SmsMessage
{
    public PhoneList $phones;
    public string $template;                 // 别名（login_code）或真实 TemplateCode
    public array $params = [];                // ['code'=>'123456'] 关联参数
    public ?string $signName = null;          // 覆盖默认签名

    public static function make(string|array $phones, string $template, array $params = []): self;
    public function to(string|array $phones): self;
    public function params(array $params): self;
    public function sign(string $sign): self;
}
```

`PhoneList` 负责：去重、号码格式校验（`InvalidPhoneException`）、通用单次上限（默认 1000）。厂商各自的上限（阿里云 1000 / 腾讯云 200）由对应 Gateway 校验。

### 7.5 `AliyunGateway`（示例策略）

```php
final class AliyunGateway implements SmsGatewayInterface
{
    public function __construct(private array $config)
    {
        // 阿里云 SDK 已是本库依赖，直接用 access_key/secret/region 构建 Client（配置态，无请求态）
    }

    public function send(SmsMessage $message): SendResult
    {
        $req = new SendSmsRequest([
            'PhoneNumbers'  => $message->phones->toString(),
            'SignName'      => $message->signName ?? $this->config['sign_name'],
            'TemplateCode'  => $this->resolveTemplate($message->template),
            'TemplateParam' => json_encode(TemplateParamMapper::toAliyun($message->params), JSON_UNESCAPED_UNICODE),
        ]);
        try {
            $resp = $this->client->sendSms($req);
        } catch (\Throwable $e) {
            throw new SmsApiException($e->getMessage(), 0, $e);
        }
        return SendResult::fromAliyun($resp->body, $message->phones); // code=OK 判定
    }
}
```

`TencentGateway` 同构：`ExternalIp`/`SmsSdkAppId`/`TemplateParam` 为**字符串数组**，由 `TemplateParamMapper::toTencent()` 适配。差异全部封在各自策略内，业务无感。

### 7.6 验证码服务 `SmsVerifier`（复用 RateLimit + Redis）

```php
final class SmsVerifier implements VerifierInterface
{
    public function __construct(
        private SmsManager $sms,
        private \Redis|CacheInterface $store,   // 库内 Redis/Cache
        private \Swoolefy\Library\RateLimit\RateLimit $limiter, // 复用限流
        private array $config,
    ) {}

    /** 冷却占位→窗口限流→生成并落码→发送；失败/抛错回滚码并释放冷却 */
    public function send(string $phone, string $template = 'login_code'): SendResult
    {
        $claimed = $this->store->add($this->cooldownKey($phone, $template), '1', $this->config['resend_interval']);
        if (!$claimed) {
            throw new RateLimitExceededException('请 N 秒后重试');   // retryAfter 取剩余 TTL
        }
        $sent = false;
        try {
            if ($this->limiter->tooMany($this->rateKey($phone))) {    // 命中窗口上限
                // retryAfter 取 DurationLimiter::getRemainingTime()，即最早一次消费滑出窗口的秒数
                throw new RateLimitExceededException('短信发送过于频繁');
            }
            $code = $this->generate();                                // CodeGenerator
            // 先落码再发送：用户收到短信时码一定可验
            $this->store->put($this->codeKey($phone), $code, $this->config['ttl']);
            $result = $this->sms->send(SmsMessage::make($phone, $template, ['code' => $code]));
            if (!$result->ok) {
                $this->store->forget($this->codeKey($phone));          // 未发出，不留可用码
                return $result;
            }
            $sent = true;
            $claimed = false;   // 已发出：冷却必须保留，finally 不再释放
            // 以下记账尽力而为：失败只影响痕迹/计数，不能把成功发送改判为失败
            $this->safeForget($this->attemptsKey($phone, $template));
            $this->safePut($this->traceKey($phone), 'active', $this->traceTtl());
            $this->safePut($this->cooldownKey($phone, $template), '1', $this->config['resend_interval']);

            return $result;
        } finally {
            if (!$sent && $this->store->exists($this->codeKey($phone))) {
                $this->store->forget($this->codeKey($phone));
            }
            if ($claimed) {
                $this->store->forget($this->cooldownKey($phone, $template));
            }
        }
    }

    /** 校验：返回 ok / mismatch / expired / absent / locked，mismatch 带剩余次数 */
    public function verify(string $phone, string $input, string $template = 'login_code'): VerifyResult;
}
```

> 限流与验证码存储**不新造机制**，直接依赖库已有的 `RateLimit`（滑动窗口/令牌桶，Lua 原子）与 `Redis`/`Cache`。

---

## 8. 用法示例

### 8.1 直接发送（策略选择）

```php
use Swoolefy\Core\Application;
use Swoolefy\Library\Sms\SmsMessage;

$sms = Application::getApp()->get('sms');

// 默认通道
$r1 = $sms->send(SmsMessage::make('13800008000', 'order_notice', ['order' => '10086']));

// 显式走腾讯云通道
$r2 = $sms->gateway('tencent')->send(
    SmsMessage::make(['13800008000', '13900009000'], 'login_code', ['code' => '8888'])
);

if (!$r2->ok) {
    // 记录 $r2->failed 号码 + requestId
}
```

### 8.2 登录验证码（编排 + 限流一体）

```php
$verifier = Application::getApp()->get('sms_verifier');

// 发送
$verifier->send($phone, 'login_code');

// 校验
$result = $verifier->verify($phone, $inputCode, 'login_code');
if (!$result->ok) {
    // status: mismatch|expired|absent|locked；mismatch 时看 remaining
    return $this->returnJson(['code' => 1, 'msg' => $result->message, 'status' => $result->status]);
}
```

### 8.3 联调/单测走 log 通道

```php
$result = Application::getApp()->get('sms')->gateway('log')->send($msg);
// 不真发；LogGateway 把消息存内存供断言
```

---

## 9. 协程安全与性能要点

1. **HTTP 阻塞需 hook**：两家 SDK 底层是 Guzzle/curl；Swoole 入口开启 `SWOOLE_HOOK_ALL`（框架默认）后自动协程化，`send()` 不阻塞 Worker。
2. **Client 无请求态**：Gateway 内构建的 SDK Client 只含 AK/SK/region，可在同请求内复用；但**禁止跨 Worker 进程 `static` 缓存 Manager**（配置/env 变更后不刷新）。
3. **限流与存储走 Redis 连接池**：`SmsVerifier` 依赖框架 `redis` 组件（已是协程连接池），不要自建长连接。
4. **群发拆分**：单请求号码数受厂商限制（如阿里 1000/次），批量群发交框架 `Job` 分片 + 重试 + 死信，组件不实现。
5. **超时**：SDK Client 设连接/读写超时（配置项 `timeout`），远端抖动时快速失败，避免协程悬挂。

---

## 10. 异常与安全

| 场景 | 异常 / 处理 |
|---|---|
| 通道未配置 | `InvalidProviderException`（对齐 Oauth/FileStorage） |
| 未安装厂商 SDK | `MissingSdkException` 提示 `composer require alibabacloud/dysmsapi-20170525` |
| 号码非法 / 超限 | `InvalidPhoneException` |
| 厂商返回业务错误（模板审核未过、余额不足、签名错误） | `SmsApiException`（保留厂商 code + requestId） |
| 触发限流 / 重发间隔 | `RateLimitExceededException` |

**安全底线**：
- `access_secret` / `secret_key` 永不进日志、不进 `SendResult`、不进异常 message。
- 日志手机号一律 `PhoneMask::mask()` → `138****8000`。
- 验证码明文不写日志。

---

## 11. 测试方案

| 层级 | 用例 | 依赖 |
|---|---|---|
| 纯逻辑 | `PhoneList` 去重/格式校验/上限、`TemplateParamMapper` 阿里 vs 腾讯格式、`SmsConfig` 合并、`CodeGenerator` 长度与字符集 | 无 |
| 策略装配 | `SmsFactory` match 到正确 Gateway；未知 gateway 抛 `InvalidProviderException`；`log` 通道不真发 | 无 |
| 验证码编排 | 用 fake Redis + fake RateLimit：命中窗口抛异常、重发间隔拦截、verify 成功销毁、失败计数达上限锁定 | 无（注入替身） |
| 厂商冒烟 `@group sms` | mock `Dysmsapi::sendSms` / `SmsClient` 返回，断言请求组装与 `SendResult` 映射 | 需 SDK 类，CI 可用 stub |

默认跑纯逻辑 + 替身组；真实发送用例标注 `@group sms`，CI skip，与库内「不强连外部服务」一致。

---

## 12. 落地里程碑

1. `SmsConfig` + `SmsMessage` + `PhoneList` + `SendResult`（领域层，无 IO）。
2. `SmsGatewayInterface` + `LogGateway` + `SmsFactory` + `SmsManager`（策略骨架可测）。
3. `AliyunGateway`（直接构造官方 Client + 参数映射 + 响应归一）。
4. `TencentGateway`。
5. `Verification`（CodeGenerator/CodeStore/SmsVerifier，接入 `RateLimit` + `Redis`）。
6. `PhoneMask` 脱敏 + 异常映射 + `src/Sms/README.md`，更新根 README「HTTP 与外部系统」新增 Sms 行。

## 13. 兼容性

- 纯新增模块，零破坏；`alibabacloud/dysmsapi-20170525`、`tencentcloud/sms` 加入 `composer.json` require。
- 与 `Oauth`/`FileStorageSystem` 保持同一 Manager/Factory/Config/Contracts/Exception 范式。
- 新增厂商实现策略类后，用 `SmsFactory::extend()` 注册，或在 `match` 加一行，不改阿里云、腾讯云代码。可选 SDK 用 `SdkGuard`。
