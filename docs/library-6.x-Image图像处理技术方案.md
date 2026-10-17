# Library 6.x Image 图像处理组件技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 命名空间 | `Swoolefy\Library\Image` |
| 模块目录 | `src/Image/` |
| 优先级 | P1 |
| 审查修订 | 2026-10-17 |
| 定位 | 缩略图 / 裁剪 / 旋转翻转 / 水印（图片 + 文字）/ 格式转换 / 编码输出的协程友好图像组件 |
| 底层选型 | [`intervention/image:^3`](https://github.com/Intervention/image)（封装 GD / Imagick driver） |

> README 与项目记忆里长期宣传「图像处理 / Intervention Image」，但 `library-6.x` 代码树里并不存在 `src/Image/`。本方案补齐这一「能力声明与代码不一致」的缺口。范式对齐已落地的 `Mail` / `Sms`：`ImageManager + Config::fromArray + Exception`，**不**再单独引入 `Imager` 门面类名。

---

## 2. 与 swoolefy 框架的职责边界

| 能力 | 归属 | 说明 |
|---|---|---|
| 日志 | **框架** `log` 组件 | 本组件通过构造函数接收 PSR-3 `LoggerInterface`，不自带 Logger |
| DI 实例化 / 协程单例 | **框架** `Application::getApp()->get('image')` | 组件在 `Config/component/image.php` 注册闭包 |
| 上传文件来源 | **框架** HTTP 请求（`UploadedFile`） | 本组件接收路径 / 二进制 / resource，不关心来源 |
| 大图异步处理 | **框架** `Job` 异步任务 | 本组件提供同步 `encode()`；投递给 Job 由业务/框架完成 |
| 图像编解码、缩放、水印 | **library** `Image` | 本方案核心 |
| 处理后落对象存储 | **library** `FileStorageSystem` | 二者组合使用，见 §8.4 |

**非目标（本方案不做）：**

- 不做 EXIF 地理/相机全量解析（仅按需读取宽高；方向校正交给 Intervention `autoOrientation`）
- 不做图像算法训练 / 人脸识别 / OCR（框架 Support/DocumentOcr 已负责文档 OCR）
- 不实现自有像素级绘图引擎，一律委托 Intervention Image + GD/Imagick
- v1 不做 GIF 多帧编辑合成（Intervention v3 虽支持动画解码，本模块 v1 只处理首帧或拒绝多帧 GIF，避免 Worker 内存尖峰）
- 不做视频首帧提取

---

## 3. 设计目标与原则

1. **可替换 driver**：配置 `gd` / `imagick`，映射到 Intervention v3 的 `Drivers\Gd\Driver` / `Drivers\Imagick\Driver`；对外 API 一致。
2. **配置驱动**：分辨率、质量、水印、格式等走配置 + 运行时 option，运行期 option 覆盖配置默认。
3. **协程安全**：`ImageManager` 只缓存 Intervention 底层 `ImageManager`（driver 句柄）与配置；**每次** `open()` / `create()` 返回独立的 `ImageEditor`，内部持有 `Intervention\Image\Interfaces\ImageInterface`，禁止跨协程共享 Editor。
4. **内存可控**：解码前用 `getimagesize()` / 文件头估算像素，超过 `max_pixels` 抛 `ImageTooLargeException`；输出字节数可选上限。
5. **可测试**：尺寸计算、option 解析、格式协商可脱离子扩展单测；真编解码用例标 `@group image`。
6. **Composer 硬依赖**：`intervention/image` 写入 `require`，构造时不做 `class_exists` 探测。仅 **PHP 扩展**（`ext-gd` / `ext-imagick`）在首次 `open()` 时检测，缺失抛 `MissingExtensionException`（与 Mail 对 PHPMailer、Sms 对官方 SDK 的策略一致：包必装，扩展按 driver 选装）。

---

## 4. Composer 依赖选型

```jsonc
{
    "require": {
        "intervention/image": "^3.9"
    }
}
```

| 依赖 | 作用 | 备注 |
|---|---|---|
| `intervention/image:^3` | 统一图像编辑门面 | v3 驱动为类名注入；PHP 8.1+，与本库 8.4 一致 |
| `ext-gd` **或** `ext-imagick` | 实际编解码后端 | 与 `image.driver` 对应；至少装一个才能 `open()` |

> Intervention 包随 Composer 安装。`driver=gd` 但未装 `ext-gd` 时，在**第一次** `open()` / `create()` 构建 driver 时失败并提示安装扩展，而不是在 DI 注册 `ImageManager` 时失败（未使用图像的应用仍可启动）。

---

## 5. 目录结构

```text
src/Image/
├── ImageManager.php               # DI 入口：open()/create()，缓存 Intervention ImageManager + 配置
├── Config/
│   └── ImageConfig.php            # fromArray() 只读访问器（外层可包 image 键）
├── ImageEditor.php                # 请求/协程级编辑链，包装 ImageInterface
├── Watermark/
│   ├── ImageWatermark.php         # logo：委托 Intervention place()
│   └── TextWatermark.php          # 文字：委托 Intervention 字体 API
├── ValueObject/
│   ├── ImageSize.php              # width/height
│   ├── EncodeOptions.php          # format/quality/interlace/strip
│   └── ProcessResult.php          # binary/mime/extension/bytes/size
├── Support/
│   ├── ExtensionGuard.php         # 检测 ext-gd / ext-imagick（不是 FileStorage 的 SdkGuard）
│   ├── SizeCalculator.php         # contain/cover 目标尺寸（纯函数）
│   └── FormatNegotiator.php       # 输出格式 vs driver 能力（如 GD 无 avif）
└── Exception/
    ├── ImageException.php
    ├── MissingExtensionException.php
    ├── UnsupportedFormatException.php
    ├── ImageTooLargeException.php
    └── InvalidImageException.php
```

不单独抽象 `ImageSourceInterface` / `ImageOutputInterface` / 自研 `GdDriver` 包装类：直接持有 Intervention 的 `ImageManager`，减少与上游 API 漂移时的维护面。

---

## 6. 配置设计

### 6.1 应用配置段 `Config/image.php`

```php
<?php
return [
    'image' => [
        // gd | imagick → 映射 Intervention\Image\Drivers\{Gd|Imagick}\Driver
        'driver' => env('IMAGE_DRIVER', 'gd'),

        // 宽*高上限，解码前校验
        'max_pixels' => 40_000_000,

        'encode' => [
            'format'      => 'jpeg',   // jpeg|png|webp|avif|gif（按 driver 能力协商）
            'quality'     => 82,
            'progressive' => true,     // JPEG 渐进；映射 Encoder 选项
        ],

        'watermark' => [
            'image' => [
                'path'     => APP_PATH . '/Resource/watermark/logo.png',
                'position' => 'bottom-right',
                'offset_x' => 16,
                'offset_y' => 16,
                'opacity'  => 0.6,
            ],
            'text' => [
                'content'  => 'swoolefy',
                'font'     => APP_PATH . '/Resource/font/DejaVuSans.ttf',
                'size'     => 28,
                'color'    => '#ffffff',
                'opacity'  => 0.5,
                'position' => 'bottom-right',
            ],
        ],

        'presets' => [
            'thumb'  => ['mode' => 'cover',   'size' => [200, 200]],
            'list'   => ['mode' => 'contain', 'size' => [750, 0]],    // 0 表示该维不限制，按比例
            'detail' => ['mode' => 'contain', 'size' => [1200, 1200]],
            'avatar' => ['mode' => 'cover',   'size' => [128, 128], 'encode' => ['format' => 'webp']],
        ],
    ],
];
```

### 6.2 `ImageConfig`

`fromArray()` 兼容外层 `image` 键。暴露 `driver()`、`encodeDefaults()`、`preset(string $name)`、`maxPixels()`、`watermarkDefaults()` 等只读方法。

### 6.3 DI 组件 `Config/component/image.php`

```php
<?php
use Swoolefy\Core\Application;
use Swoolefy\Library\Image\ImageManager;

return [
    'image' => static function (): ImageManager {
        $c = include APP_PATH . '/Config/image.php';
        return new ImageManager($c['image'], Application::getApp()->get('log'));
    },
];
```

`ImageManager` 只缓存 Intervention 底层 manager 与配置，**不缓存**任何 `ImageInterface` 实例。

---

## 7. 核心类设计

### 7.1 `ImageManager`（DI 入口，无请求态）

```php
final class ImageManager
{
    public function __construct(
        private array|ImageConfig $config,
        private ?\Psr\Log\LoggerInterface $logger = null,
    ) {
        if (is_array($this->config)) {
            $this->config = ImageConfig::fromArray($this->config);
        }
    }

    /** 路径 / 二进制 / resource / SplFileInfo 等，语义同 Intervention read() */
    public function open(string|resource|\SplFileInfo $source): ImageEditor;

    /** 空白画布，对应 Intervention create() */
    public function create(int $width, int $height, string $background = '#ffffff'): ImageEditor;
}
```

首次 `open()` / `create()` 时按 `driver` 构建 Intervention `ImageManager` 并缓存。

### 7.1.1 与 Intervention v3 的语义对齐（重要）

Intervention v3 的 `ImageInterface` 上多数修改方法是**就地修改**当前实例（如 `scale()`、`cover()`、`place()`），不是 v2 那种全局可变单例。本库 `ImageEditor` 的行为：

- 对外仍提供链式 API，内部委托 `ImageInterface`。
- 需要从**同一张原图**派生多档尺寸（thumb / list / detail）时，应对**同一输入多次 `open()`**，或 `clone` 底层 `ImageInterface` 后再各自编辑；**不要**假设 `$origin->applyPreset('thumb')` 之后 `$origin` 仍是原图（见 §8.4 修正示例）。
- 需要「分支互不影响」时，在 `open()` 之后立刻 `clone` 一份再分别编辑。

### 7.2 `ImageEditor`

```php
final class ImageEditor
{
    /** contain：等比缩进画布；cover：等比铺满后居中裁剪；scale：按宽或高缩放；crop 手动矩形 */
    public function resize(?int $w, ?int $h, string $mode = 'contain'): self;
    public function thumbnail(int $maxW, int $maxH): self;   // 只缩不放
    public function crop(int $x, int $y, int $w, int $h): self;
    public function rotate(float $degrees): self;
    public function flipHorizontal(): self;
    public function flipVertical(): self;                      // 对应 Intervention flip / flop
    public function greyscale(): self;
    public function watermarkImage(ImageWatermark $mark): self;
    public function watermarkText(TextWatermark $mark): self;
    public function applyPreset(string $name): self;

    public function encode(?EncodeOptions $options = null): ProcessResult;
    public function toBinary(?EncodeOptions $options = null): string;
    public function toBase64(?EncodeOptions $options = null): string;
    public function size(): ImageSize;
}
```

映射关系（实现时对照 Intervention 文档，避免自造轮子）：

| 本库 | Intervention v3 |
|---|---|
| `resize(..., contain)` | `scaleDown()` / `contain()` 等等价组合 |
| `resize(..., cover)` | `cover()` |
| `watermarkImage` | `place()` |
| `watermarkText` | `text()` + 字体配置 |
| `encode` | `encode()` / `encodeByExtension()` → `EncodedImage` |

### 7.3 水印

- `ImageWatermark`：可读 logo 路径（拒绝 `..`），透明度与九宫格定位后调用 `place()`。
- `TextWatermark`：TTF 路径必须存在；字号 / 颜色 / 透明度 / 角度走 Intervention 字体 API。
- 配置默认来自 `image.watermark.*`，代码可覆盖。

---

## 8. 用法示例

### 8.1 Controller 生成缩略图

```php
use Swoolefy\Core\Application;
use Swoolefy\Library\Image\ValueObject\EncodeOptions;

$image = Application::getApp()->get('image');

$result = $image
    ->open('/data/upload/origin.jpg')
    ->applyPreset('list')
    ->encode(new EncodeOptions(format: 'webp', quality: 80));

return $this->returnBin($result->binary, $result->mime);
```

### 8.2 头像：裁剪 + 图片水印

```php
$bin = $image->open($avatarPath)
    ->resize(256, 256, mode: 'cover')
    ->watermarkImage(ImageWatermark::fromConfig())
    ->toBinary(new EncodeOptions(format: 'png'));
```

### 8.3 文字水印 → base64

```php
$data = $image->open($src)
    ->watermarkText(new TextWatermark(content: '机密', size: 32, opacity: 0.35))
    ->toBase64(new EncodeOptions(format: 'jpeg', quality: 75));
```

### 8.4 与 FileStorageSystem 组合（多档派生）

```php
$fs = Application::getApp()->get('file_storage')->disk();
$img = Application::getApp()->get('image');
$sourcePath = $fs->getObject('uploads/2026/a.jpg');

foreach (['thumb', 'list', 'detail'] as $preset) {
    // 每档从同一源重新 open，避免就地修改污染下一档
    $one = $img->open($sourcePath)->applyPreset($preset)->encode();
    $fs->putObject("derivatives/{$preset}/a." . $one->extension, $one->binary, [
        'mime' => $one->mime,
    ]);
}
```

---

## 9. 协程安全与性能要点

1. **禁止跨协程共享 `ImageEditor`**：底层是 GD/Imagick 句柄，请求结束释放。
2. **CPU 密集**：编解码占满当前 Worker；大图 / 批量派生走 `Job` 或独立进程池。
3. **`max_pixels`**：解码前估算，超限不读全图。
4. **Imagick**：`encode()` 后在 finally 中 `clear()` / `destroy()`（由 Editor 封装，业务不直接碰）。
5. **只读资源可进程缓存**：水印 logo 路径、TTF 路径校验结果可按 key 缓存；**图像像素不缓存**。

---

## 10. 异常与扩展检测

| 场景 | 异常 |
|---|---|
| `driver=gd` 但无 `ext-gd` | `MissingExtensionException`（提示安装 php-gd 或改 driver） |
| `driver=imagick` 但无 `ext-imagick` | 同上 |
| 输入无法解码 | `InvalidImageException` |
| 输出格式当前 driver 不支持（如 GD 无 avif） | `UnsupportedFormatException`，列出可用格式 |
| 像素或输出字节超限 | `ImageTooLargeException` |

业务统一 `catch (ImageException $e)`。

---

## 11. 测试方案

| 层级 | 内容 | 依赖 |
|---|---|---|
| 纯函数 | `SizeCalculator`、`FormatNegotiator`、`EncodeOptions` 合并 | 无 |
| Driver 冒烟 `@group image` | `create(1,1)` → encode PNG，断言魔数与宽高 | gd 或 imagick |
| 水印 | canvas + 文字水印，字节数大于无水印 | gd |
| 边界 | `max_pixels`、非法二进制 | 无扩展可测大部分 |

CI 默认不跑 `image` 组；无扩展时 `markTestSkipped`。

---

## 12. 落地里程碑

1. `ImageConfig` + `ImageManager` + `ExtensionGuard` + `open/create/encode`（gd）冒烟。
2. `resize/crop/thumbnail/rotate/flip/greyscale` + `presets`。
3. 图片 / 文字水印 + 配置默认。
4. `imagick` driver 映射 + `FormatNegotiator`（avif/webp 等）。
5. `ProcessResult` 元信息、FileStorage 组合示例、`src/Image/README.md`、根 README 一行。

## 13. 兼容性

- 纯新增，零破坏。
- PHP `>= 8.4`，与现库风格一致。
- `intervention/image` 为硬依赖；扩展按 driver 选装，首次 `open()` 才检测扩展是否可用。
