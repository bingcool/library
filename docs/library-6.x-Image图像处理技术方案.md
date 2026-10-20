# Library 6.x Image 图像处理组件技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 命名空间 | `Swoolefy\Library\Image` |
| 模块目录 | `src/Image/` |
| 优先级 | P1 |
| 审查修订 | 2026-10-20（底层版本修正为 `intervention/image` 4.4.x） |
| 定位 | 缩略图 / 裁剪 / 旋转翻转 / 水印（图片 + 文字）/ 格式转换 / 编码输出的协程友好图像组件 |
| 底层选型 | [`intervention/image:~4.4.0`](https://github.com/Intervention/image)（v4，`composer.lock` 锁定 4.4.0，封装 GD / Imagick driver） |

> `src/Image/` 已按本方案落地，底层使用 **`intervention/image` 4.4.x（v4）**，不再是 3.x。v3 → v4 的方法更名与坐标、透明度、缩放语义差异见 §7.1.2，迁移与排障必须以 v4 语义为准。范式对齐已落地的 `Mail` / `Sms`：`ImageManager + Config::fromArray + Exception`，**不**再单独引入 `Imager` 门面类名。

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
- v1 不做 GIF 多帧编辑合成（Intervention v4 虽支持动画解码，本模块 v1 只处理首帧或拒绝多帧 GIF，避免 Worker 内存尖峰）
- 不做视频首帧提取

---

## 3. 设计目标与原则

1. **可替换 driver**：配置 `gd` / `imagick`，映射到 Intervention v4 的 `Drivers\Gd\Driver` / `Drivers\Imagick\Driver`；对外 API 一致。
2. **配置驱动**：分辨率、质量、水印、格式等走配置 + 运行时 option，运行期 option 覆盖配置默认。
3. **协程安全**：`ImageManager` 只缓存 Intervention 底层 `ImageManager`（driver 句柄）与配置；**每次** `open()` / `create()` 返回独立的 `ImageEditor`，内部持有 `Intervention\Image\Interfaces\ImageInterface`，禁止跨协程共享 Editor。
4. **内存可控**：路径用 `getimagesize()`、二进制用 `getimagesizefromstring()`、流读头部（最多 1MB）估算像素，超过 `max_pixels` 抛 `ImageTooLargeException`；`resize`/`cover`/`crop` 与 logo/overlay 的目标尺寸同样受约束；输出字节数可选上限。
5. **可测试**：尺寸计算、option 解析、格式协商可脱离子扩展单测；真编解码用例标 `@group image`。
6. **Composer 硬依赖**：`intervention/image` 写入 `require`，构造时不做 `class_exists` 探测。仅 **PHP 扩展**（`ext-gd` / `ext-imagick`）在首次 `open()` 时检测，缺失抛 `MissingExtensionException`（与 Mail 对 PHPMailer、Sms 对官方 SDK 的策略一致：包必装，扩展按 driver 选装）。

---

## 4. Composer 依赖选型

```jsonc
{
    "require": {
        "intervention/image": "~4.4.0"
    }
}
```

| 依赖 | 作用 | 备注 |
|---|---|---|
| `intervention/image:~4.4.0` | 统一图像编辑门面 | v4 驱动为类名注入；要求 PHP ^8.3，与本库 8.4 一致 |
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
│   ├── ImageWatermark.php         # logo：委托 Intervention insert()
│   └── TextWatermark.php          # 文字：委托 Intervention 字体 API
├── ValueObject/
│   ├── ImageSize.php              # width/height
│   ├── EncodeOptions.php          # format/quality/progressive/strip/maxBytes
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

        // 相对路径基准；默认 APP_PATH，再退化为进程 CWD
        'base_path' => APP_PATH,

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
                // 允许读取 logo 的根目录；默认注入 [base_path]，[] 表示不额外限制
                'allowed_roots' => [APP_PATH . '/Resource/watermark'],
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

`fromArray()` 兼容外层 `image` 键。暴露 `driver()`、`basePath()`、`encodeDefaults()`、`preset(string $name)`、`maxPixels()`、`watermarkImage()`、`watermarkText()` 等只读方法。

- `driver()`：只接受 `gd` / `imagick`，其余值抛 `InvalidConfigException`，不再静默回退。
- `basePath()`：相对路径基准，优先级 `base_path` > `APP_PATH` > 进程 CWD。
- `watermarkImage()`：未显式配置时注入 `base_path = basePath()` 与 `allowed_roots = [basePath()]`，使水印默认只能读取项目目录内的文件。

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
        // 构造期只校验配置取值；扩展是否安装仍延迟到首次 open/create
        $this->config->driver();
    }

    /** 路径 / 二进制 / resource / SplFileInfo 等，语义同 Intervention read() */
    public function open(string|resource|\SplFileInfo $source): ImageEditor;

    /** 空白画布，对应 Intervention create() */
    public function create(int $width, int $height, string $background = '#ffffff'): ImageEditor;
}
```

首次 `open()` / `create()` 时按 `driver` 构建 Intervention `ImageManager` 并缓存。

### 7.1.1 与 Intervention v4 的语义对齐（重要）

Intervention v4 的 `ImageInterface` 上多数修改方法是**就地修改**当前实例（如 `scale()`、`cover()`、`insert()`），不是 v2 那种全局可变单例。本库 `ImageEditor` 的行为：

- 对外仍提供链式 API，内部委托 `ImageInterface`。
- 需要从**同一张原图**派生多档尺寸（thumb / list / detail）时，应对**同一输入多次 `open()`**，或 `clone` 底层 `ImageInterface` 后再各自编辑；**不要**假设 `$origin->applyPreset('thumb')` 之后 `$origin` 仍是原图（见 §8.4 修正示例）。
- 需要「分支互不影响」时，在 `open()` 之后立刻 `clone` 一份再分别编辑。

### 7.1.2 Intervention Image 4.4.x 语义差异（迁移必读）

当前依赖固定为 **`intervention/image` 4.4.x（v4）**，不是 3.x。v4 在方法名与参数语义上有以下差异，`ImageEditor` 与业务代码均需按 v4 实现：

| 主题 | v3 | v4（当前） |
|---|---|---|
| 叠加图片 | `place()` | `insert($image, $x, $y, $alignment, $transparency)` |
| `insert()` 坐标 | — | `$x/$y` 是相对 `$alignment` 锚点的偏移，不是绝对坐标 |
| `insert()` 透明度 | — | `$transparency` 是**不透明度因子**：`1.0` 原图、`0.0` 全透明 |
| `text()` 坐标 | 绝对坐标 + `align()` | 语义不变：position 是锚点坐标，配合 `font->align()` |
| 等比缩放 | `resize()` 可等比 | `resize()` 宽高独立拉伸；等比用 `scale()` / `scaleDown()` |
| `contain()` | 等比缩进、不补白 | 固定目标画布、居中、背景填充；不补白用 `scaleDown()` |
| PNG 质量 | 支持 | `PngEncoder` 无 quality，仅 `interlaced/indexed/strip` |
| 8 位 hex alpha | — | `#RRGGBBAA` 的 alpha 为 0–255 |
| 方向校正 | 需显式 `orient()` | 解码器默认 `autoOrientation=true`，通常无需再次调用 |
| 异常 | — | 抛 `Intervention\Image\Exceptions\*`，需在模块边界包装为 `ImageException` |

**实现约束**：

1. `watermarkImage()` 不得把 `PositionAnchor` 算出的绝对坐标直接传给 `insert()`；应传相对锚点的内缩量（右侧/底部为正数）。
2. 配置项 `opacity` 语义为「越大越不透明」，传给 `insert()` 时直接使用，不得取反。
3. 文字水印的 `#RRGGBBAA` alpha 按 0–255 计算；颜色先经 `Rgb\Color::parse()` 归一化，避免 3 位 hex / 命名色拼接出非法值。
4. `contain` 若需保持「不补白、不放大」，使用 `scaleDown()`；若需固定画布，显式新增 `pad` 模式并配置背景色。
5. 输出格式必须与编码器匹配：bmp/tiff 目前没有对应 encoder，不应出现在 `FormatNegotiator` 白名单中，除非补齐编码器。
6. 相对路径统一以 `base_path` 解析（默认 `APP_PATH`，再退化到 CWD），并拒绝 `..` 段；图片水印还必须通过 `allowed_roots` 白名单。
7. 本地文件解码基于 `fopen()` 文件句柄（`decodeStream`）而非 `getimagesize($path)` + `decodePath($path)`，解码后再复核宽高，缩小 TOCTOU 窗口。
8. 可 seek 流先 `rewind()` 到起点；不可 seek 流复制到 `php://temp`。EXIF 方向由 v4 `autoOrientation` 处理一次，`open()` 不再重复 `orient()`。

> 上述约束已于 2026-10-20 随 P0/P1/P2 修复落地（见 [Image 模块审查修复报告](./library-6.x-Image-审查修复报告.md) §8.5 ~ §8.7）。

### 7.2 `ImageEditor`

```php
final class ImageEditor
{
    /** contain：等比、只缩不放、不补白；cover：等比铺满后居中裁剪；scale：等比缩放；stretch/fill：强制拉伸（单维等比）。v4 语义见 §7.1.2 */
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

| 本库 | Intervention v4 |
|---|---|
| `resize(..., contain)` | `scaleDown()`（等比、只缩不放、不补白） |
| `resize(..., cover)` | `cover()` |
| `watermarkImage` | `insert()`（x/y 为相对锚点偏移，transparency 为不透明度因子） |
| `watermarkText` | `text()` + 字体配置 |
| `encode` | `encode()` + `Encoders\*` → `EncodedImageInterface` |

### 7.3 水印

- `ImageWatermark`：可读 logo 路径（拒绝 `..`，经 `realpath` 解析，并按 `allowed_roots` 白名单校验），按九宫格锚点调用 v4 `insert()`；`$x/$y` 是相对锚点的偏移（右侧/底部为正数表示内缩），`$transparency` 是**不透明度因子**（`1.0` 原图、`0.0` 全透明），配置项 `opacity` 可直接传入。
- `TextWatermark`：TTF 路径必须存在且可读，相对路径以 `base_path` 为基准；字号 / 颜色 / 透明度 / 角度走 Intervention 字体 API。
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
    ->watermarkImage(ImageWatermark::fromConfig($image->config()))
    ->toBinary(new EncodeOptions(format: 'png'));
```

### 8.3 文字水印 → base64

```php
$data = $image->open($src)
    ->watermarkText(new TextWatermark(
        content: '机密',
        font: APP_PATH . '/Resource/font/DejaVuSans.ttf',
        size: 32,
        opacity: 0.35,
    ))
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
3. **`max_pixels`**：路径/二进制/流均在解码前估算；`resize`/`cover`（含中间画布）/`crop` 与 logo、overlay 在分配内存前校验。
4. **Imagick**：`encode()` 后在 finally 中 `clear()` / `destroy()`（由 Editor 封装，业务不直接碰）。
5. **只读资源可进程缓存**：水印 logo 路径、TTF 路径校验结果可按 key 缓存；**图像像素不缓存**。
6. **路径与能力检测**：水印 logo / 字体按 `base_path` 解析并缓存 `realpath`，避免 CWD 差异；`FormatNegotiator` 每次按 `gd_info()` / `Imagick::queryFormats()` 过滤，部署未编译的格式会直接报 `UnsupportedFormatException`。
7. **解码入口**：文件来源用 `fopen()` 句柄预检并解码，流来源先 `rewind()`；解码后再复核 `max_pixels`，防止预检与解码之间被替换。

---

## 10. 异常与扩展检测

| 场景 | 异常 |
|---|---|
| `driver` 不是 `gd` / `imagick` | `InvalidConfigException`（构造时） |
| `driver=gd` 但无 `ext-gd` | `MissingExtensionException`（提示安装 php-gd 或改 driver） |
| `driver=imagick` 但无 `ext-imagick` | 同上 |
| 输入无法解码 | `InvalidImageException` |
| 水印路径含 `..` 或不在 `allowed_roots` 内 | `InvalidImageException` |
| 输出格式当前 driver/运行时不支持（如 GD 无 avif） | `UnsupportedFormatException`，列出可用格式 |
| GIF 编码传 `strip=true` | `UnsupportedFormatException` |
| 像素或输出字节超限 | `ImageTooLargeException` |

业务统一 `catch (ImageException $e)`。

---

## 11. 测试方案

| 层级 | 内容 | 依赖 |
|---|---|---|
| 纯函数 | `SizeCalculator`、`FormatNegotiator`、`EncodeOptions` 合并 | 无 |
| Driver 冒烟 `@group image` | `create(1,1)` → encode PNG，断言魔数与宽高 | gd 或 imagick |
| 水印 | canvas + 文字水印，字节数大于无水印 | gd |
| 边界 | `max_pixels`、非法二进制、流 rewind、EXIF 只校正一次 | 无扩展可测大部分 |
| P2 健壮性 | driver 校验、运行时能力、quality/thumbnail、透明默认 PNG、`base_path`/`allowed_roots`、overlay 复用、`encodePngBinary` 统一管线 | gd |

CI 默认不跑 `image` 组；无扩展时 `markTestSkipped`。

---

## 12. 落地里程碑

1. `ImageConfig` + `ImageManager` + `ExtensionGuard` + `open/create/encode`（gd）冒烟。
2. `resize/crop/thumbnail/rotate/flip/greyscale` + `presets`。
3. 图片 / 文字水印 + 配置默认。
4. `imagick` driver 映射 + `FormatNegotiator`（avif/webp 等）。
5. `ProcessResult` 元信息、FileStorage 组合示例、`src/Image/README.md`、根 README 一行。
6. 健壮性补强（P2）：driver 严格校验、运行时能力检测、路径白名单、TOCTOU/流 rewind、默认格式与参数校验。

## 13. 兼容性

- 纯新增，零破坏。
- PHP `>= 8.4`，与现库风格一致。
- `intervention/image` 为硬依赖，版本约束 `~4.4.0`（v4，要求 PHP ^8.3）；扩展按 driver 选装，首次 `open()` 才检测扩展是否可用。
- 已从 v3 升级到 v4：`place()` 由 `insert()` 取代，坐标、透明度、`contain()`、编码器参数均以 v4 语义为准，详见 §7.1.2。
- P2 行为变化：非法 `driver` 改为抛 `InvalidConfigException`；未配置 `format` 时透明源默认 `png`；GIF 传 `strip=true` 显式报错；`encodePngBinary()` 统一走 `encode()`（可能触发 `max_bytes`）；水印相对路径改以 `base_path` 为基准且默认受 `allowed_roots` 约束。
