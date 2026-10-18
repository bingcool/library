# Qrcode

二维码**生成**（仅 encode，不含扫码解码），基于 [bacon/bacon-qr-code 3.x](https://github.com/Bacon/BaconQrCode)。链式 Builder 输出 PNG / SVG / EPS / Data URI Base64。PNG 走 `GDLibRenderer`，需要 **ext-gd**；SVG / EPS 需要 **ext-xmlwriter**（PHP 常见内置）。

可选 **Logo 居中叠加**依赖 `Image` 组件（构造 `QrcodeManager` 时注入 `ImageManager`）；未注册时 `withLogo()` 后在 `png()` 抛 `QrcodeException`。

设计见 [docs/library-6.x-Qrcode与Barcode技术方案.md](../../docs/library-6.x-Qrcode与Barcode技术方案.md) § Qrcode。

## 和框架的分工

| 能力 | 归属 | 说明 |
|---|---|---|
| 编码与渲染 | **library** `Qrcode` | 本模块 |
| 读 `Config/qrcode.php` | **library** `QrcodeConfig` | 支持外层 `qrcode` 键 |
| DI | **框架** `get('qrcode')` | 见下方组件注册 |
| HTTP 输出 | **框架** Controller `returnBin` | 传入 `QrcodeResult->binary` 与 mime |
| 落存储 | **library** `FileStorageSystem` | 与二进制结果组合 |
| 支付 / 业务 URL | **业务** | 本模块只编码字符串，不解析微信/支付宝语义 |
| 日志 | **框架** `log` | Manager 第二参数预留 PSR-3 |

`QrcodeManager` **无请求态**，可跨协程共享；每次 `make()` / `url()` / `wifi()` 新建 `QrcodeBuilder`。不要在 Builder 上跨协程复用。

## 依赖

| 包 / 扩展 | 用途 |
|---|---|
| `bacon/bacon-qr-code ~3.1` | Composer 硬依赖 |
| `ext-gd` | PNG |
| `ext-xmlwriter` | SVG、EPS |
| `Image`（可选） | `withLogo()` 叠加 |

## 配置

`Config/qrcode.php` 由 `QrcodeManager` 注入，`QrcodeConfig` 只读解析。各段**作用**概览：

| 配置段 | 作用（解决什么问题） | 何时生效 |
|---|---|---|
| `default` | 全站二维码「默认长什么样」：边长、静区、纠错、颜色、base64 默认格式 | 每次 `make()` / `url()` / `wifi()` 创建 Builder 的初值；链式方法可覆盖 |
| `logo` | 约束 Logo 叠加策略（最大占比）；`path`/`enabled` 供运维约定，**不会自动叠 Logo** | `logo.ratio` 在 `withLogo()` 时作为比例上限；叠图仍须代码里 `withLogo($path)` |
| `strict_length` | 内容过长时给前端/调用方统一的错误语义 | Bacon 编码失败且为容量问题时 |

> 配置**不会**代替业务拼 URL；支付链接、订单号等仍由业务传入 `make($content)` / `url($url)`。Logo **不会**因 `logo.enabled=true` 自动出现，必须 `->withLogo(...)->png()`。

`Config/qrcode.php`：

```php
<?php
/**
 * 作用：集中定义二维码生成的默认视觉参数、Logo 策略上限、内容过长时的错误策略。
 * 读取：QrcodeManager 构造 → QrcodeConfig::fromArray()（可只传内层数组，会自动识别外层 qrcode 键）。
 */
return [
    'qrcode' => [
        /*
         * default
         * 作用：新建 QrcodeBuilder 时的默认渲染参数，避免每个 Controller 重复写 size=300、level=M。
         * 覆盖：链式 ->size() / ->errorCorrection() / ->foreground() 等优先于此处。
         */
        'default' => [
            // 作用：输出图像边长（像素），影响扫码距离与页面展示大小；映射 Bacon RendererStyle.size
            'size'   => 300,
            // 作用：二维码四周「静区」宽度（模块数），过小可能导致部分扫码器识别率下降
            'margin' => 1,
            // 作用：纠错等级 L|M|Q|H；越高越抗污损/Logo 遮挡，但同尺寸可编码字数越少。带 Logo 建议 Q 或 H
            'level'  => 'M',
            // 作用：base64() 未传 format 时的默认输出：png|svg|eps（与终结方法 png()/svg()/eps() 无关）
            'format' => 'png',
            // 作用：模块颜色 [背景 hex, 前景 hex]；品牌色、深色模式可在此统一
            'fill'   => ['#ffffff', '#000000'],
        ],

        /*
         * logo
         * 作用：规范「中心 Logo」最大占比与默认资源路径（文档/运维用）。
         * 说明：v1 不读 enabled 自动叠图；须在代码 withLogo($path, $ratio)，且 ratio ≤ logo.ratio。
         *       需要 Image 组件注入 QrcodeManager 第三参数。
         */
        'logo' => [
            // 作用：是否启用 Logo 能力（配置标记；实际仍要代码调用 withLogo）
            'enabled' => false,
            // 作用：默认 Logo 文件路径，便于团队统一资源位置；withLogo() 可传此 path 或业务自定义
            'path'    => APP_PATH . '/Resource/watermark/logo.png',
            // 作用：withLogo 允许的最大 ratio（Logo 边长占码图宽度）；超过抛 InvalidQrcodeContentException
            'ratio'   => 0.18,
        ],

        /*
         * strict_length
         * 作用：Bacon 因容量编不下时，是否统一抛「内容过长」类 InvalidQrcodeContentException。
         * true：便于 API 返回固定错误码/文案；false：透传 Bacon 原始异常信息便于排查。
         */
        'strict_length' => true,
    ],
];
```

组件注册（`Config/component/qrcode.php` 或合并进 `Config/component/`）：

```php
<?php
use Swoolefy\Core\Application;
use Swoolefy\Library\Qrcode\QrcodeManager;

return [
    'qrcode' => static function (): QrcodeManager {
        $c = include APP_PATH . '/Config/qrcode.php';
        return new QrcodeManager(
            $c['qrcode'],
            Application::getApp()->get('log'),
            Application::getApp()->get('image'), // 不用 Logo 时可省略第三参数，或传 null
        );
    },
];
```

`QrcodeConfig::fromArray()` 兼容外层再包一层 `qrcode`。

## 配置项使用详解

### `default` 各字段

| 字段 | 作用 | 代码对应 |
|---|---|---|
| `size` | 控制二维码像素边长，列表小图可 120，海报可 400+ | `QrcodeBuilder::size()`；Builder 构造时从配置带入初值 |
| `margin` | 留白，避免贴边被 UI 裁切 | `QrcodeBuilder::margin()` |
| `level` | 抗损与容量权衡；Logo 场景用 Q/H | `QrcodeBuilder::errorCorrection()` |
| `format` | 仅影响 `base64()` 默认格式 | `QrcodeBuilder::base64(null)` |
| `fill[0]` / `fill[1]` | 背景色 / 模块色 | `background()` / `foreground()` |

```php
$qr = Application::getApp()->get('qrcode');

// 使用配置 default，只改内容
$result = $qr->make('https://example.com')->png();

// 在 default 之上覆盖
$result = $qr->url('https://example.com/pay/1')
    ->size(240)
    ->errorCorrection('H')
    ->foreground('#003366')
    ->png();
```

### `logo` 段

| 字段 | 作用 | 实际行为（v1） |
|---|---|---|
| `enabled` | 产品/运维开关说明 | **不**自动叠 Logo |
| `path` | 团队默认 Logo 路径 | 需手动 `withLogo(APP_PATH . '...')` 或读配置拼 path |
| `ratio` | Logo 最大占比上限 | `withLogo($path, $ratio)` 时 `$ratio` 不得超过此值 |

```php
// DI 须注入 image，见上方组件注册
$png = $qr->make('https://example.com')
    ->errorCorrection('H')   // Logo 须 Q 或 H
    ->withLogo(APP_PATH . '/Resource/watermark/logo.png', 0.16)
    ->png();
```

### `strict_length`

长 URL、大段 JSON 放入二维码时，Bacon 会尝试升高 version；仍超出容量则失败。`strict_length=true` 时统一为「内容过长」类业务异常，便于接口返回 400；调试时可设为 `false` 看 Bacon 原文。

### 配置与输出格式

| 调用 | 是否读 `default.format` |
|---|---|
| `->png()` / `->svg()` / `->eps()` | 否，由方法决定 |
| `->base64()` | 是，未传参时用 `default.format` |
| `->base64('svg')` | 否，以参数为准 |

### 读取配置（封装 Service 时）

Builder 已自动合并 `default`；若业务要读上限或默认值（例如动态 clamp Logo 比例）：

```php
use Swoolefy\Library\Qrcode\Config\QrcodeConfig;

$cfg = QrcodeConfig::fromArray(include APP_PATH . '/Config/qrcode.php');
$maxRatio = $cfg->maxLogoRatio();       // logo.ratio，默认 0.18
$defaults = $cfg->defaults();           // size、margin、level、format、fill
$strict   = $cfg->strictLength();
```

---

## 使用

### 链接与 Base64

```php
use Swoolefy\Core\Application;

$qr = Application::getApp()->get('qrcode');

$dataUri = $qr->url('https://example.com/pay/ORDER123')
    ->errorCorrection('H')
    ->size(240)
    ->foreground('#1a1a1a')
    ->background('#ffffff')
    ->base64('png');

// 或终结方法
$result = $qr->make('任意 UTF-8 文本，含中文')->svg();
// $result->binary, $result->mime, $result->format, $result->content
```

### WiFi 连接串

`wifi()` 内部用 `WifiStringBuilder` 生成 `WIFI:T:...;S:...;P:...;;`，并对 `\ ; , "` 等转义：

```php
$wifi = $qr->wifi('MyHome', 'secret', 'WPA')->svg();
// auth: WPA | WEP | nopass（NONE 归一为 nopass）
```

### 纠错与内容长度

| level | 说明 |
|---|---|
| L | 约 7% 纠错 |
| M | 约 15%（默认） |
| Q | 约 25%，**Logo 最低要求 Q** |
| H | 约 30%，Logo 推荐 |

内容为空（trim 后）→ `InvalidQrcodeContentException`。超长时 Bacon 自动升 version；仍放不下且 `strict_length=true` → `InvalidQrcodeContentException`（统一「内容过长」文案）。

### 带 Logo（PNG）

须在 **`png()` 之前**链式调用 `withLogo()`；纠错须 **Q 或 H**；`ratio` 须 `> 0` 且 `≤` 配置 `logo.ratio`（默认 0.18）：

```php
$png = $qr->make('https://example.com')
    ->errorCorrection('H')
    ->size(300)
    ->withLogo(APP_PATH . '/Resource/logo.png', 0.16)
    ->png();
```

流程：Bacon 生成 PNG → `ImageManager::open($binary)` → 居中缩放 Logo → `encodePngBinary()`。SVG / EPS **不支持** Logo 叠加。

v1 **不做**码下方 human-readable label（由前端 HTML 排版）。

## 异常

| 场景 | 异常 | Job 重试 |
|---|---|---|
| 空内容、WiFi 参数非法、Logo 比例/纠错不满足 | `InvalidQrcodeContentException` | 否 |
| 未知 format（base64 参数等） | `UnsupportedQrcodeFormatException` | 否 |
| 缺 gd / xmlwriter | `MissingExtensionException` | 否 |
| withLogo 但未注入 Image | `QrcodeException` | 否 |

## 常用 API 速查

| 方法 | 说明 |
|---|---|
| `QrcodeManager::make($content)` | 通用 Builder |
| `QrcodeManager::url($url)` | 等价 make，语义化 |
| `QrcodeManager::wifi($ssid, $password, $auth)` | WiFi payload |
| `QrcodeBuilder::content` / `size` / `margin` | 覆盖内容与尺寸 |
| `QrcodeBuilder::errorCorrection('L'|'M'|'Q'|'H')` | 纠错 |
| `QrcodeBuilder::foreground` / `background` | hex 颜色 |
| `QrcodeBuilder::withLogo($path, $ratio)` | 仅 PNG，需 Image |
| `QrcodeBuilder::png()` / `svg()` / `eps()` | 返回 `QrcodeResult` |
| `QrcodeBuilder::base64(?$format)` | Data URI |

## 非目标（v1）

- 不做扫码识别 / 解码
- 不做 PDF 码图
- 测试不断言「可被真实扫码器识别」（只断言格式与 magic bytes）

## 测试

```bash
./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Qrcode
```

SVG 用例无需 gd；PNG / Logo 用例标 `@group qrcode` 并在无 gd 时 skip。
