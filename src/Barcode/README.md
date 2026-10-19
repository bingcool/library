# Barcode

一维条码**生成**（仅 encode），基于 [picqer/php-barcode-generator 3.x](https://github.com/picqer/php-barcode-generator)。支持 CODE_128、CODE_39、EAN-13/8、UPC-A、ITF 等；默认 **SVG** 无需图像扩展；**PNG** 需要 **ext-gd** 或 **ext-imagick** 至少其一。

设计见 [docs/library-6.x-Qrcode与Barcode技术方案.md](../../docs/library-6.x-Qrcode与Barcode技术方案.md) § Barcode。

## 和框架的分工

| 能力 | 归属 | 说明 |
|---|---|---|
| 制式校验 + 渲染 | **library** `Barcode` | TypeMapper + picqer Renderers |
| 读 `Config/barcode.php` | **library** `BarcodeConfig` | 支持外层 `barcode` 键 |
| DI | **框架** `get('barcode')` | 组件闭包 |
| HTTP / 存储 | **框架** + **FileStorageSystem** | 同 Qrcode |
| 日志 | **框架** `log` | Manager 第二参数预留 |

`BarcodeManager` 无状态；每次 `make($content)` 新建 `BarcodeBuilder`。

## 依赖

| 包 / 扩展 | 用途 |
|---|---|
| `picqer/php-barcode-generator ~3.3` | Composer 硬依赖 |
| （无） | 默认 SVG |
| `ext-gd` 或 `ext-imagick` | PNG（优先 gd 的 `PngRenderer::useGd()`） |

## 制式与内容规则（摘要）

| `BarcodeType` | picqer 类型 | 内容要求（TypeMapper 预检） |
|---|---|---|
| `CODE_128` | TypeCode128 | 非空即可，细节由 picqer 处理 |
| `CODE_39` | TypeCode39 | 自动转大写；字符集 `0-9A-Z-. $/+%` |
| `EAN_13` | TypeEan13 | 纯数字，12 或 13 位 |
| `EAN_8` | TypeEan8 | 纯数字，7 或 8 位 |
| `UPC_A` | TypeUpcA | 纯数字，11 或 12 位 |
| `ITF` | TypeInterleaved25 | 纯数字 |

非法内容 → `InvalidBarcodeContentException`（含 picqer 编码失败时包装）。

## 配置

`Config/barcode.php`：

```php
<?php
return [
    'barcode' => [
        'default' => [
            'type'      => 'CODE_128',   // 映射 BarcodeType enum
            'height'    => 150,           // 条图高度（像素）
            'width'     => 0,             // 0=自动：max(200, intrinsic×2)；>0 为输出总宽
            'show_text' => true,          // SVG 在底部叠加人类可读文字；PNG 受 picqer v3 限制无效
            'format'    => 'svg',         // base64() 默认：svg|png
        ],
    ],
];
```

> picqer v3 的 `width` 是**输出总宽度**，不是 v2 的 `widthFactor`。若需旧版「模块宽度倍数」，用 `$barcode->getWidth() * $factor` 传给 `width()`。

组件注册：

```php
<?php
use Swoolefy\Core\Application;
use Swoolefy\Library\Barcode\BarcodeManager;

return [
    'barcode' => static function (): BarcodeManager {
        $c = include APP_PATH . '/Config/barcode.php';
        return new BarcodeManager($c['barcode'], Application::getApp()->get('log'));
    },
];
```

`BarcodeConfig::fromArray()` 兼容外层 `barcode` 键。

## 使用

### SVG（推荐，零扩展）

```php
use Swoolefy\Core\Application;
use Swoolefy\Library\Barcode\BarcodeType;

$bc = Application::getApp()->get('barcode');

$svg = $bc->make('081231723897')
    ->type(BarcodeType::CODE_128)
    ->height(120)
    ->svg();

return $this->returnBin($svg->binary, $svg->mime);
```

### EAN / UPC

```php
$ean = $bc->make('6901234567890')
    ->type(BarcodeType::EAN_13)
    ->svg();

$upc = $bc->make('012345678905')
    ->type(BarcodeType::UPC_A)
    ->height(80)
    ->png();   // 需要 gd 或 imagick
```

### Base64 Data URI

```php
$data = $bc->make('SKU-9981')
    ->type(BarcodeType::CODE_39)
    ->base64('svg');   // null 时用配置 default.format
```

### 链式 API

```php
$bc->make($payload)
    ->content('新载荷')     // 覆盖
    ->type(BarcodeType::ITF)
    ->width(400)           // 固定输出宽
    ->height(150)
    ->showText(false)
    ->png();
```

## 异常

| 场景 | 异常 | Job 重试 |
|---|---|---|
| 空内容、位数/字符集不符、picqer 失败 | `InvalidBarcodeContentException` | 否 |
| base64 未知 format | `UnsupportedBarcodeTypeException` | 否 |
| PNG 且 gd/imagick 均未装 | `MissingExtensionException` | 否 |

## 常用 API 速查

| 方法 | 说明 |
|---|---|
| `BarcodeManager::make($content)` | 新建 Builder，初值来自 default 配置 |
| `BarcodeBuilder::content` / `type` / `height` / `width` / `showText` | 链式配置 |
| `BarcodeBuilder::svg()` / `png()` | 返回 `BarcodeResult` |
| `BarcodeBuilder::base64(?$format)` | Data URI |
| `BarcodeResult` | `binary`, `mime`, `format`, `content` |

## 非目标（v1）

- 不做条码扫描 / 解码
- 不做 PDF、标签打印机专用布局
- 测试不断言「可被扫码枪识别」

## 测试

```bash
./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Barcode
```

PNG 用例标 `@group barcode`；无 gd/imagick 时 skip。SVG 与校验逻辑不依赖扩展。
