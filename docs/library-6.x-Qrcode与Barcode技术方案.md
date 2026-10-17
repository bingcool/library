# Library 6.x Qrcode 二维码 / Barcode 条形码技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块目录 | `src/Qrcode/`、`src/Barcode/`（两个兄弟模块，一份方案） |
| 命名空间 | `Swoolefy\Library\Qrcode`、`Swoolefy\Library\Barcode` |
| 优先级 | P1 |
| 审查修订 | 2026-10-17 |
| 定位 | 二维码与条形码的**生成**（仅 encode）：PNG / SVG / EPS / Base64、纠错等级、尺寸/边距、Logo 叠加、多种条码制式 |
| QR 选型 | [`bacon/bacon-qr-code:^3`](https://github.com/Bacon/BaconQrCode) |
| Barcode 选型 | [`picqer/php-barcode-generator:^3`](https://github.com/picqer/php-barcode-generator) |

> 与 [Image 方案](library-6.x-Image图像处理技术方案.md) 配套：二维码 Logo 居中叠加在 v1 **可选**依赖 `Image` 模块；未启用 Image 时 `withLogo()` 抛明确异常，其余输出不受影响。

---

## 2. 与 swoolefy 框架的职责边界

| 能力 | 归属 |
|---|---|
| 生成码图二进制 | **library** `Qrcode` / `Barcode` |
| HTTP 响应输出 | **框架** Controller `returnBin` |
| 落对象存储 / 直链 | **library** `FileStorageSystem` |
| DI | **框架** `Application::getApp()->get('qrcode')` / `get('barcode')` |
| 日志 | **框架** `log`（可选 PSR-3） |

**非目标：**

- 不做扫码识别 / 解码（Bacon 目前也只有 encoder；测试不断言「可被扫描识别」）
- 不做支付业务语义（微信/支付宝链接由业务拼装字符串）
- 不实现 Reed-Solomon，委托 `bacon/bacon-qr-code`
- v1 不做 PDF 输出（Bacon 无 PDF backend；若后续需要单独评估）

---

## 3. 设计目标与原则

1. **Builder + 终结方法**：链式配置内容、尺寸、纠错、颜色，`->png()` / `->svg()` / `->eps()` / `->base64()` 输出。
2. **对齐上游 v3 API**：QR 使用 Bacon 的 `Writer` + `Renderer`（如 `GDLibRenderer`、`ImageRenderer` + `SvgImageBackEnd`）；Barcode 使用 picqer v3 的 `Types\Type*` + `Renderers\*Renderer`。
3. **Composer 硬依赖**：两个包写入 `require`，不做 `class_exists` 探测。PNG 输出额外需要 `ext-gd`（或 Bacon 的 Imagick backend，本库 v1 **统一** PNG 走 `GDLibRenderer` + `ext-gd`，与 Image 默认 driver 一致）。
4. **SVG 无 GD**：SVG / EPS 纯 PHP；SVG 依赖 `ext-xmlwriter`（PHP 常见内置）。
5. **与 Image 协作**：`withLogo()` 在 PNG 生成后交给 `Image` 叠加，不在 Qrcode 内重复实现绘图。
6. **协程安全**：Manager 无状态；Builder 每次 `make()` 新建；不进程级缓存码图结果。

---

## 4. Composer 依赖选型

```jsonc
{
    "require": {
        "bacon/bacon-qr-code": "^3.0",
        "picqer/php-barcode-generator": "^3.0"
    }
}
```

| 依赖 | 用途 | 运行时要求 |
|---|---|---|
| `bacon/bacon-qr-code` | QR 编码 + Renderer | PNG：`ext-gd`；SVG/EPS：`ext-xmlwriter` |
| `picqer/php-barcode-generator` | 一维条码 | SVG：`SvgRenderer`；PNG：`PngRenderer` + `ext-gd` |

---

## 5. 目录结构

### 5.1 `src/Qrcode/`

```text
src/Qrcode/
├── QrcodeManager.php
├── Config/QrcodeConfig.php
├── QrcodeBuilder.php
├── Renderer/
│   ├── PngRenderer.php          # Bacon GDLibRenderer
│   ├── SvgRenderer.php          # ImageRenderer + SvgImageBackEnd
│   └── EpsRenderer.php
├── ValueObject/
│   ├── QrcodeOptions.php
│   └── QrcodeResult.php         # binary/mime/format/contentHash
├── Support/
│   ├── ErrorCorrectionMapper.php
│   ├── WifiStringBuilder.php    # WIFI: 协议串 + 转义
│   └── ExtensionGuard.php       # ext-gd / ext-xmlwriter
└── Exception/
    ├── QrcodeException.php
    ├── InvalidQrcodeContentException.php
    ├── UnsupportedQrcodeFormatException.php
    └── MissingExtensionException.php
```

### 5.2 `src/Barcode/`

```text
src/Barcode/
├── BarcodeManager.php
├── Config/BarcodeConfig.php
├── BarcodeBuilder.php
├── BarcodeType.php              # enum → picqer Type* 类
├── Renderer/
│   ├── SvgRenderer.php
│   └── PngRenderer.php
├── ValueObject/
│   ├── BarcodeOptions.php
│   └── BarcodeResult.php
├── Support/
│   ├── TypeMapper.php           # 内容校验 + Type 实例
│   └── ExtensionGuard.php
└── Exception/
    ├── BarcodeException.php
    ├── InvalidBarcodeContentException.php
    └── UnsupportedBarcodeTypeException.php
```

不复制 FileStorage 的 `SdkGuard::requireClass()` 来检测 Bacon/picqer（包已 hard require）。`ExtensionGuard` 只负责 **ext-gd** 等扩展。

---

## 6. 配置设计

### 6.1 `Config/qrcode.php`

```php
return [
    'qrcode' => [
        'default' => [
            'size'   => 300,              // 目标边长（像素），映射 RendererStyle
            'margin' => 1,                // 静区（模块数）
            'level'  => 'M',              // L|M|Q|H
            'format' => 'png',            // png|svg|eps
            'fill'   => ['#ffffff', '#000000'],
        ],
        'logo' => [
            'enabled' => false,
            'path'    => APP_PATH . '/Resource/watermark/logo.png',
            'ratio'   => 0.18,            // Logo 占码宽比例；建议 level≥Q
        ],
        // 可选：内容超过 Bacon 当前 version 容量时是否抛异常（默认 true）
        'strict_length' => true,
    ],
];
```

### 6.2 `Config/barcode.php`

```php
return [
    'barcode' => [
        'default' => [
            'type'      => 'CODE_128',
            'height'    => 150,           // 渲染高度（picqer v3 Renderer）
            'width'     => 0,             // 0=按条码 intrinsic 宽度；>0 为最终输出宽度
            'show_text' => true,
            'format'    => 'svg',
        ],
    ],
];
```

> picqer v3 的 `width` 语义与 v2 的 `widthFactor` 不同：若要保持旧版「模块宽度倍数」，应取 `$barcode->getWidth() * $factor` 再传给 Renderer（见 picqer README）。

### 6.3 DI 组件

```php
<?php
use Swoolefy\Core\Application;
use Swoolefy\Library\Qrcode\QrcodeManager;
use Swoolefy\Library\Barcode\BarcodeManager;

return [
    'qrcode' => static function (): QrcodeManager {
        $c = include APP_PATH . '/Config/qrcode.php';
        return new QrcodeManager($c['qrcode'], Application::getApp()->get('log'));
    },
    'barcode' => static function (): BarcodeManager {
        $c = include APP_PATH . '/Config/barcode.php';
        return new BarcodeManager($c['barcode'], Application::getApp()->get('log'));
    },
];
```

---

## 7. 核心类设计

### 7.1 `QrcodeBuilder`

```php
final class QrcodeBuilder
{
    public function content(string $text): self;
    public function size(int $px): self;
    public function margin(int $modules): self;
    public function errorCorrection(string $level): self;  // L|M|Q|H
    public function foreground(string $hex): self;
    public function background(string $hex): self;

    /** 链式修饰：在终结输出前调用，不是终结方法本身 */
    public function withLogo(string $logoPath, float $ratio = 0.18): self;

    public function png(): QrcodeResult;
    public function svg(): QrcodeResult;
    public function eps(): QrcodeResult;
    public function base64(?string $format = null): string;
}
```

- `content()` 为空 → `InvalidQrcodeContentException`。UTF-8 中文/emoji 走 Bacon 默认 byte 模式；超长由 Bacon 升 version，仍放不下且 `strict_length=true` 时抛 `InvalidQrcodeContentException`。
- `withLogo()`：先 `png()` 得到位图，再 `Application::getApp()->get('image')` 打开并居中缩放 Logo；**必须** `errorCorrection('Q'|'H')` 且 `ratio` 不超过配置上限，否则抛 `InvalidQrcodeContentException`。Image 组件未注册时抛 `QrcodeException` 并说明依赖。
- v1 **不做**码下 `label` 文字（Bacon 无内置；若需要由业务在 HTML 层排版，或 v2 用 SVG 后处理）。

### 7.2 `QrcodeManager`

```php
final class QrcodeManager
{
    public function make(string $content): QrcodeBuilder;
    public function url(string $url): QrcodeBuilder;
    public function wifi(string $ssid, string $pass, string $auth = 'WPA'): QrcodeBuilder;
}
```

`wifi()` 由 `WifiStringBuilder` 生成 `WIFI:T:WPA;S:...;P:...;;`，对 `\`, `;`, `,`, `"` 等做转义，避免扫不出来。

### 7.3 `BarcodeBuilder` + `BarcodeType`

```php
enum BarcodeType: string {
    case CODE_128 = 'CODE_128';
    case CODE_39  = 'CODE_39';
    case EAN_13   = 'EAN_13';
    case EAN_8    = 'EAN_8';
    case UPC_A    = 'UPC_A';
    case ITF      = 'ITF';
}

final class BarcodeBuilder
{
    public function content(string $code): self;
    public function type(BarcodeType $t): self;
    public function height(int $px): self;
    public function width(int $px): self;
    public function showText(bool $show = true): self;
    public function svg(): BarcodeResult;
    public function png(): BarcodeResult;
    public function base64(?string $format = null): string;
}
```

`TypeMapper`：EAN-13 仅数字且 12/13 位（可自动补校验位）、CODE_39 字符集等，非法内容抛 `InvalidBarcodeContentException`，再调用 picqer `Type*`。

---

## 8. 用法示例

### 8.1 支付链接 → base64

```php
$qr = Application::getApp()->get('qrcode');

$data = $qr->url('https://example.com/pay/ORDER123')
    ->errorCorrection('H')
    ->size(240)
    ->base64('png');
```

### 8.2 带 Logo → OSS

```php
$result = $qr->make('https://example.com')
    ->errorCorrection('H')
    ->withLogo(APP_PATH . '/Resource/watermark/logo.png', 0.16)
    ->png();

$disk->putObject('qrcode/order-123.png', $result->binary, ['mime' => $result->mime]);
```

### 8.3 EAN-13 → SVG

```php
$bc = Application::getApp()->get('barcode');
$result = $bc->make('6901234567890')
    ->type(BarcodeType::EAN_13)
    ->height(120)
    ->svg();

return $this->returnBin($result->binary, $result->mime);
```

### 8.4 WiFi

```php
$bin = $qr->wifi('MyHome_5G', 'p@ssw0rd', 'WPA')->png()->binary;
```

---

## 9. 协程安全与性能

1. Manager 可跨协程共享；Builder 不可复用给不同内容。
2. 批量出码（万级券码）走 Job，不在 Web 请求里循环。
3. 前端能显示 SVG 时优先 `svg()`，无 GD、体积小。
4. 业务侧按需用 Redis 按内容 hash 缓存，本库不内置。

---

## 10. 异常与扩展检测

| 场景 | 异常 |
|---|---|
| 内容为空 / 超长 / WiFi 参数非法 | `InvalidQrcodeContentException` / `InvalidBarcodeContentException` |
| PNG 但缺 `ext-gd` | `MissingExtensionException`（提示装 gd 或改 svg） |
| SVG 但缺 `ext-xmlwriter` | `MissingExtensionException` |
| 不支持的 format / type | `UnsupportedQrcodeFormatException` / `UnsupportedBarcodeTypeException` |
| withLogo 但 Image 未注册 | `QrcodeException` |

基类 `QrcodeException` / `BarcodeException`。

---

## 11. 测试方案

| 层级 | 用例 | 依赖 |
|---|---|---|
| 纯逻辑 | `ErrorCorrectionMapper`、`WifiStringBuilder` 转义、`TypeMapper` 校验 | 无 |
| Writer `@group qrcode` | 固定 payload → SVG 含 `<svg`；PNG 魔数 | gd（PNG） |
| Logo | withLogo 后 PNG 字节变化、尺寸不变 | gd + Image |
| Barcode `@group barcode` | CODE_128 SVG 非空、EAN 非法位数抛异常 | 无 / gd |

**不做** Bacon 解码回环测试（与非目标一致）。Logo 用例只断言图像层面变化，不引入扫码库。

---

## 12. 落地里程碑

1. `QrcodeConfig` + `SvgRenderer` + `base64/svg`（无 gd 可跑 CI）。
2. `PngRenderer` + `ErrorCorrectionMapper` + `url()` / `wifi()`。
3. `withLogo()`（依赖 Image，可后置）。
4. Barcode：`TypeMapper` + SVG + PNG。
5. 两模块 README + 根 README 两行。

## 13. 兼容性

- 纯新增；Bacon / picqer 硬依赖写入 `composer.json`。
- 与 Image 解耦可组合：仅 Logo 路径依赖 Image。
