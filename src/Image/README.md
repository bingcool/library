# Image

基于 [Intervention Image 4](https://image.intervention.io/) 的图像处理：解码、缩放裁剪、旋转翻转、灰度、图片/文字水印与多格式编码。`ImageManager::open()` / `create()` 返回**请求级** `ImageEditor`；大图异步处理交给框架 Job，本组件只提供同步 `encode()`。

设计见 [docs/library-6.x-Image图像处理技术方案.md](../../docs/library-6.x-Image图像处理技术方案.md)。

## 和框架的分工

| 能力 | 归属 | 说明 |
|---|---|---|
| 编解码、缩放、水印 | **library** `Image` | 本模块 |
| 读 `Config/image.php` | **library** `ImageConfig` | 支持外层再包一层 `image` |
| 实例化 | **框架** `Application::getApp()->get('image')` | 组件闭包注册 |
| 上传来源 | **框架** HTTP `UploadedFile` 等 | 本组件只接收路径 / 二进制 / 流 |
| 大图异步 / 批量派生 | **框架** `Job` | 每档尺寸在 Job 内多次 `open()` |
| 落对象存储 | **library** `FileStorageSystem` | 与 `ProcessResult->binary` 组合 |
| 日志 | **框架** `log` | Manager 注入 PSR-3，当前版本预留 |

`ImageEditor` 是**请求态**：内部持有 Intervention 解码后的位图，**禁止**跨协程共享或放进 Worker 级 static 缓存。

> `ImageManager` 只缓存 **driver 对应的 Intervention ImageManager** 与 `ImageConfig`，**不缓存**任何已解码图像。首次 `open()` / `create()` 才检测 `ext-gd` / `ext-imagick`；未使用图像的应用仍可正常启动。

## 依赖与 driver

| 包 / 扩展 | 说明 |
|---|---|
| `intervention/image ~4.4` | Composer **硬依赖**，构造时不做 `class_exists` 探测 |
| `ext-gd` **或** `ext-imagick` | 与 `image.driver` 对应，**至少装一个**才能 `open()` |

| driver | Intervention Driver | 格式能力（摘要） |
|---|---|---|
| `gd`（默认） | `Drivers\Gd\Driver` | jpeg、png、webp、gif 等；**无** avif |
| `imagick` | `Drivers\Imagick\Driver` | 含 avif 等（视 ImageMagick 编译选项） |

非法 `driver` 配置会回退为 `gd`。

## 配置

`Config/image.php` 整文件由 `ImageManager` 注入，`ImageConfig` 只读解析。各段**作用**概览：

| 配置段 | 作用（解决什么问题） | 何时生效 |
|---|---|---|
| `driver` | 选定 PHP 用 GD 还是 Imagick 做解码/编码，适配不同机器环境 | 第一次 `open()` / `create()` |
| `max_pixels` | 拒绝超大图进内存，防止用户上传千万像素图拖垮 Swoole Worker | `open()` / `create()` 及编码后体积校验 |
| `encode` | 统一「导出图」的格式、质量、体积策略，避免业务里到处写死 jpeg 82 | 每次 `encode()` 的默认基线 |
| `watermark` | 统一品牌 Logo / 版权文字的位置与样式，运营改配置即可，不必改 PHP | 代码调用 `watermarkImage()` / `watermarkText()` 时 |
| `presets` | 统一缩略图、列表图、详情图等**规格名**，上传派生多档尺寸时全站一致 | 代码调用 `applyPreset('thumb')` 等时 |

> `watermark`、`presets` **不会**在 `open()` 后自动执行；必须在 Editor 链上显式调用对应方法。用法见「配置项使用详解」。

`Config/image.php`：

```php
<?php
/**
 * 作用：集中定义图像组件的运行策略（后端、安全上限、导出默认值、水印样式、多档尺寸）。
 * 读取：ImageManager 构造时 → ImageConfig::fromArray()（可只传内层数组，会自动识别外层 image 键）。
 */
return [
    'image' => [
        /*
         * driver
         * 作用：决定 Intervention 使用哪套 PHP 扩展做像素运算，影响可用输出格式与部署依赖。
         * 取值：gd（默认，易装）| imagick（需 ext-imagick，格式更全如 avif）。
         * 说明：非法值回退 gd；未装对应扩展时在首次 open/create 抛 MissingExtensionException。
         */
        'driver' => env('IMAGE_DRIVER', 'gd'),

        /*
         * max_pixels
         * 作用：限制单张图解码后的最大像素（宽×高），作为上传/处理链路的安全阀。
         * 场景：用户上传相机原图、恶意大图时尽早失败，而不是占满 Worker 内存。
         * 说明：文件路径会先 getimagesize 预检；0=不限制（不推荐生产使用）。
         */
        'max_pixels' => 40_000_000,

        /*
         * encode
         * 作用：定义「保存/输出」时的默认格式与质量，供接口直出、写 OSS、生成缓存文件共用。
         * 优先级：全局 encode < preset 内 encode < encode(new EncodeOptions(...))。
         */
        'encode' => [
            // 作用：默认存什么格式（影响体积、透明通道、浏览器兼容）。gd 不支持 avif。
            'format'      => 'jpeg',
            // 作用：控制有损压缩程度，平衡清晰度与 CDN 流量（主要影响 jpeg/webp/avif）。
            'quality'     => 82,
            // 作用：JPEG 渐进加载，弱网下预览体验更好（仅 jpeg 有意义）。
            'progressive' => true,
            // 作用：去掉 EXIF/GPS 等元数据，降低隐私与体积（用户上传图对外发布时常开）。
            // 'strip'       => true,
            // 作用：限制编码后文件最大字节，防止单张输出过大（如误配超高 quality）。
            // 'max_bytes'   => 8 * 1024 * 1024,
        ],

        /*
         * watermark
         * 作用：为「防盗图 / 品牌露出 / 标注来源」提供默认 Logo 与文字样式；
         *       多环境（测试/生产）可换 path 而不改业务代码。
         * 使用：ImageWatermark::fromConfig($cfg) / TextWatermark::fromConfig($cfg, [...])。
         */
        'watermark' => [
            /*
             * watermark.image
             * 作用：在成图上叠加半透明 Logo（活动页、公开图库、下载预览等）。
             */
            'image' => [
                // 作用：指定 Logo 文件；PNG 透明底可只显示标识不挡画面。
                'path'     => APP_PATH . '/Resource/watermark/logo.png',
                // 作用：Logo 贴在图的哪一角/中心，避免挡住主体时可改 bottom-right → center 等。
                'position' => 'bottom-right',  // top-left, center, bottom-right …（Intervention Alignment）
                // 作用：Logo 与画布边缘留白，避免贴边裁切或贴 UI 太近。
                'offset_x' => 16,
                'offset_y' => 16,
                // 作用：Logo 可见程度；越小越透，越大越醒目。
                'opacity'  => 0.6,
            ],

            /*
             * watermark.text
             * 作用：叠加版权、站点名、用户 ID、拍摄日期等文字（内网图、凭证图、防泄露追踪）。
             */
            'text' => [
                // 作用：默认水印文案；按用户/订单动态改时用 fromConfig(..., ['content' => '...'])。
                'content'  => 'swoolefy',
                // 作用：指定渲染字体；中文环境需部署含汉字的 TTF。
                'font'     => APP_PATH . '/Resource/font/DejaVuSans.ttf',
                // 作用：文字大小，与画布分辨率配合调整。
                'size'     => 28,
                // 作用：字色；深底用浅色字，浅底用深色字。
                'color'    => '#ffffff',
                // 作用：文字可见程度，常与较低 opacity 做「隐约版权」。
                'opacity'  => 0.5,
                'position' => 'bottom-right',
                'offset_x' => 16,
                'offset_y' => 16,
                // 作用：斜向水印（如全屏「样本」），0 为水平。
                // 'angle'    => 0,
            ],
        ],

        /*
         * presets
         * 作用：把「缩略图 / 列表图 / 详情图 / 头像」等常用输出尺寸起名字，
         *       上传后 Job 或 Controller 按名派生，全站规格一致、改一处配置即可。
         * 使用：$manager->open($原图)->applyPreset('list')->encode()。
         * 注意：每一档 preset 应对原图重新 open()，不能一个 Editor 连 apply 多个 preset。
         */
        'presets' => [
            /*
             * thumb
             * 作用：网格/相册小图、后台列表缩略图，固定方图便于 UI 对齐。
             * mode cover：填满 200×200，多余部分居中裁掉。
             */
            'thumb' => [
                'mode' => 'cover',
                'size' => [200, 200],
            ],

            /*
             * list
             * 作用：信息流、商品列表主图，限制宽度加快列表加载，高度随比例。
             * size[750,0]：宽不超过 750，高 0 表示不限制该维。
             */
            'list' => [
                'mode' => 'contain',
                'size' => [750, 0],
            ],

            /*
             * detail
             * 作用：详情页大图，在可控像素内展示清晰内容，避免原图直出过大。
             */
            'detail' => [
                'mode' => 'contain',
                'size' => [1200, 1200],
            ],

            /*
             * avatar
             * 作用：用户头像等小圆/方形容器，体积小、加载快；webp 进一步省流量。
             * preset.encode：仅在本 preset 导出时用 webp，不影响其他 preset 仍用全局 jpeg。
             */
            'avatar' => [
                'mode'   => 'cover',
                'size'   => [128, 128],
                'encode' => [
                    'format' => 'webp',
                ],
            ],
        ],
    ],
];
```

组件注册（`Config/component/image.php`）：

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

`ImageConfig::fromArray()` 兼容外层再包一层 `image`。

## 配置项使用详解

本节说明 `watermark.image`、`watermark.text`、`presets` 在配置文件里写什么、在代码里怎么读、怎样接到 `ImageEditor` 链上。对应 typed 配置类：`ImageWatermarkConfig`、`TextWatermarkConfig`、`ImagePresetConfig` / `ImagePresetsConfig`。

### 使用前须知

1. **配置不会自动生效**  
   写好 `watermark` / `presets` 后，不会在 `open()` 时自动打水印或自动缩图。必须显式调用 `applyPreset()` / `watermarkImage()` / `watermarkText()`，再 `encode()`。

2. **读取配置**  
   DI 取到的 `ImageManager` 已内置 `ImageConfig`：

   ```php
   $manager = Application::getApp()->get('image');
   $cfg = $manager->config();
   ```

   若只有数组，可用 `ImageConfig::fromArray($c['image'])`。

3. **推荐操作顺序**  
   一般：**缩放 / preset → 水印 → encode**。水印按当前画布尺寸计算位置。

### 配置段与类的对应关系

| php 配置段 | Config 类 | 运行时对象（可选） | Editor 方法 |
|---|---|---|---|
| `watermark.image` | `ImageWatermarkConfig` | `ImageWatermark` | `watermarkImage()` |
| `watermark.text` | `TextWatermarkConfig` | `TextWatermark` | `watermarkText()` |
| `presets.{name}` | `ImagePresetConfig` | （直接用 Config） | `applyPreset()` / `applyPresetConfig()` |

---

### `watermark.image`（图片 Logo 水印）

#### 字段说明

| 字段 | 含义 |
|---|---|
| `path` | Logo 文件路径（PNG 透明底常用） |
| `position` | 九宫格锚点，如 `bottom-right`、`center`、`top-left`（Intervention `Alignment` 支持的写法） |
| `offset_x` / `offset_y` | 相对锚点的边距（像素） |
| `opacity` | 0~1，**越大越不透明**（如 `0.6` 为半透明 Logo） |

#### 读取与运行时覆盖

```php
use Swoolefy\Library\Image\Config\ImageConfig;
use Swoolefy\Library\Image\Watermark\ImageWatermark;

$cfg = $manager->config();

$imgWmCfg = $cfg->watermarkImage();
// $imgWmCfg->path, ->position, ->offsetX, ->offsetY, ->opacity

$imgWmCfg = $cfg->watermarkImage()->withOverrides([
    'opacity' => 0.85,
    'position' => 'center',
]);
```

`withOverrides()` 的键名与 php 配置一致（`offset_x`、`offset_y` 等）。

#### 叠到图上

配置 → `ImageWatermark`（校验 path 可读、拒绝 `..`）→ `watermarkImage()`：

```php
$editor = $manager->open('/data/upload/photo.jpg');

// 方式 A：typed 配置（推荐）
$editor->watermarkImage(ImageWatermark::fromWatermarkConfig($cfg->watermarkImage()));

// 方式 B：读配置并覆盖部分字段
$editor->watermarkImage(ImageWatermark::fromConfig($cfg, [
    'path' => APP_PATH . '/Resource/watermark/activity.png',
]));

// 方式 C：完全手写，不读配置文件
$editor->watermarkImage(new ImageWatermark(
    APP_PATH . '/Resource/wm.png',
    'bottom-right',
    16,
    16,
    0.5
));

$result = $editor->encode();
```

---

### `watermark.text`（文字水印）

#### 字段说明

| 字段 | 含义 |
|---|---|
| `content` | 水印文字（不能为空，否则构造 `TextWatermark` 抛错） |
| `font` | TTF/OTF 路径，须存在且可读 |
| `size` | 字号 |
| `color` | 颜色 hex，如 `#ffffff` |
| `opacity` | 0~1，越大越不透明 |
| `position` / `offset_x` / `offset_y` | 同图片水印 |
| `angle` | 旋转角度（度），默认 `0`；可在 php 配置或 `withOverrides` 中设置 |

#### 读取与叠字

```php
use Swoolefy\Library\Image\Watermark\TextWatermark;

$textWmCfg = $cfg->watermarkText()->withOverrides([
    'content' => '内部资料 · ' . date('Y-m-d'),
]);

$editor = $manager->open($path)
    ->watermarkText(TextWatermark::fromWatermarkConfig($textWmCfg));

// 或
$editor->watermarkText(TextWatermark::fromConfig($cfg, ['content' => '机密']));
```

图片 + 文字可连续调用（顺序按视觉需求）：

```php
$editor = $manager->open($path)
    ->watermarkImage(ImageWatermark::fromConfig($cfg))
    ->watermarkText(TextWatermark::fromConfig($cfg, ['content' => 'sample.com']));
```

---

### `presets`（命名尺寸模板）

每条 preset 解析为 `ImagePresetConfig`：`mode`、宽高（来自 `size`）、可选 `encode`。

#### `mode` 与 `size`

| mode | 行为（`ImageEditor::resize`） |
|---|---|
| `contain` | 等比缩进框内，不裁切 |
| `cover` | 等比铺满框，居中裁剪（方图 thumb / avatar） |
| `scale` / `stretch` / `fill` | 强制拉到指定宽高 |

`size => [宽, 高]` 中 **0 表示该维度不限制**：

| 预设示例 | 含义 |
|---|---|
| `thumb`：`cover` + `[200, 200]` | 200×200 方图缩略图 |
| `list`：`contain` + `[750, 0]` | 宽不超过 750，高度按比例 |
| `detail`：`contain` + `[1200, 1200]` | 详情图缩进框内 |
| `avatar`：`cover` + `[128, 128]` + `encode` | 头像方图，且该档默认 webp |

#### 读取

```php
$list = $cfg->preset('list');
// $list->mode, $list->width, $list->height, $list->encodeOptions()

foreach ($cfg->presets()->names() as $name) {
    // thumb, list, detail, avatar ...
}
```

#### 应用

按配置名：

```php
$result = $manager->open('/data/upload/a.jpg')
    ->applyPreset('list')
    ->encode();
```

代码内自定义 preset（不必写进 php 文件）：

```php
use Swoolefy\Library\Image\Config\ImagePresetConfig;

$custom = ImagePresetConfig::fromArray([
    'mode' => 'contain',
    'size' => [600, 400],
    'encode' => ['format' => 'webp', 'quality' => 85],
]);

$result = $manager->open($path)
    ->applyPresetConfig($custom)
    ->encode();
```

#### preset 里的 `encode`

全局 `encode.format` 若为 `jpeg`，而 `avatar` 预设带 `'encode' => ['format' => 'webp']`，则 **`applyPreset('avatar')` 之后** 的 `encode()` 默认用 webp；仍可在本次 `encode(new EncodeOptions(...))` 中再覆盖。

---

### 多档派生（同一张原图）

Intervention 编辑多为就地修改。每个 preset **单独 `open()` 一次**：

```php
$path = '/data/upload/a.jpg';
$names = $manager->config()->presets()->names();

foreach ($names as $preset) {
    $one = $manager->open($path)
        ->applyPreset($preset)
        ->encode();

    // $one->binary, $one->mime, $one->extension → 存 OSS / 写磁盘
}
```

---

### `encode()` 优先级

合并顺序（后者覆盖前者）：

1. 全局 `Config/image.php` 的 **`encode`**
2. 若调用了 **`applyPreset` / `applyPresetConfig`** 且 preset 带 **`encode` 段**
3. **`encode(new EncodeOptions(...))`** 传入的参数

```php
// 全局 jpeg；list 无 encode → jpeg
$manager->open($path)->applyPreset('list')->encode();

// avatar 带 webp → webp
$manager->open($path)->applyPreset('avatar')->encode();

// 再强制 png
$manager->open($path)->applyPreset('avatar')->encode(new EncodeOptions(format: 'png'));
```

---

### 完整业务示例

上传原图后：列表图、带 Logo 缩略图、带动态文字详情图。

```php
use Swoolefy\Core\Application;
use Swoolefy\Library\Image\ValueObject\EncodeOptions;
use Swoolefy\Library\Image\Watermark\ImageWatermark;
use Swoolefy\Library\Image\Watermark\TextWatermark;

$manager = Application::getApp()->get('image');
$cfg = $manager->config();
$originPath = '/data/upload/user/2026/photo.jpg';
$userId = 10086;

$listBin = $manager->open($originPath)
    ->applyPreset('list')
    ->encode()
    ->binary;

$thumbBin = $manager->open($originPath)
    ->applyPreset('thumb')
    ->watermarkImage(ImageWatermark::fromConfig($cfg))
    ->encode()
    ->binary;

$detailBin = $manager->open($originPath)
    ->applyPreset('detail')
    ->watermarkText(TextWatermark::fromConfig($cfg, [
        'content' => 'ID:' . $userId,
        'opacity' => 0.35,
    ]))
    ->encode(new EncodeOptions(quality: 88))
    ->binary;
```

Controller 直出单档：

```php
$result = $manager->open($path)->applyPreset('list')->encode();
return $this->returnBin($result->binary, $result->mime);
```

---

### 常见错误

| 情况 | 结果 |
|---|---|
| Logo `path` 空、不存在、含 `..` | `InvalidImageException` |
| 文字 `content` 空或 `font` 不可读 | `InvalidImageException` |
| `applyPreset('xxx')` 但配置无 xxx | `InvalidImageException` |
| gd driver 下要求 avif | `UnsupportedFormatException` |
| 原图像素超过 `max_pixels` | `ImageTooLargeException`（`open` 阶段） |

---

## 使用

### 打开来源与解码

`open(mixed $source)` 识别顺序：

| 来源 | 行为 |
|---|---|
| `\SplFileInfo` | `decodeSplFileInfo`，路径受 `max_pixels` 预检 |
| PHP `resource`（流） | `decodeStream` |
| 存在且为**文件**的 string | `decodePath` |
| 其他 string | 按**二进制** `decodeBinary`（勿把不存在路径当二进制误传） |

成功后自动 `orient()`（按 EXIF 校正方向）。`ImageException` 子类（如 `ImageTooLargeException`）**原样抛出**，其余解码失败包装为 `InvalidImageException`。

```php
use Swoolefy\Core\Application;
use Swoolefy\Library\Image\ValueObject\EncodeOptions;
use Swoolefy\Library\Image\Watermark\ImageWatermark;
use Swoolefy\Library\Image\Watermark\TextWatermark;

$image = Application::getApp()->get('image');

$result = $image->open('/data/upload/a.jpg')
    ->applyPreset('list')
    ->encode(new EncodeOptions(format: 'webp', quality: 80));

// Controller 直出
return $this->returnBin($result->binary, $result->mime);
```

### 多档派生（重要）

Intervention v4 的编辑多为**就地修改**。从同一张原图生成 thumb / list / detail 时，应对**同一输入多次 `open()`**，不要复用已 `applyPreset` 的 Editor：

```php
$path = '/data/upload/a.jpg';
foreach (['thumb', 'list', 'detail'] as $preset) {
    $one = $image->open($path)->applyPreset($preset)->encode();
    // 写入存储或写磁盘…
}
```

### 缩放与水印

```php
$editor = $image->open($path)
    ->resize(800, 600, 'contain')   // contain|cover|scale|stretch|fill
    ->thumbnail(1200, 1200)         // 只缩小不放大
    ->crop(0, 0, 400, 400)
    ->rotate(90)
    ->greyscale();

// 从 typed 配置段构建（推荐）
$cfg = ImageConfig::fromArray($c['image']);
$editor->watermarkImage(ImageWatermark::fromWatermarkConfig($cfg->watermarkImage()));
$editor->watermarkText(TextWatermark::fromWatermarkConfig(
    $cfg->watermarkText()->withOverrides(['content' => '内部资料'])
));

// 或一行：fromConfig 等价于 watermarkImage()/watermarkText() + withOverrides
$editor->watermarkImage(ImageWatermark::fromConfig($cfg));
$editor->watermarkText(TextWatermark::fromConfig($cfg, ['content' => '内部资料']));

$base64 = $editor->toBase64(new EncodeOptions(format: 'jpeg', quality: 85));
```

### 空白画布

```php
$png = $image->create(320, 240, '#336699')
    ->encode(new EncodeOptions(format: 'png'));
```

### 与 Qrcode Logo 协作

`ImageEditor::overlayCenter()` / `encodePngBinary()` 供 Qrcode 模块在 PNG 码图正中叠加 Logo；业务代码一般只通过 `QrcodeBuilder::withLogo()` 使用。

## 异常与是否重试

| 场景 | 异常 | Job 是否重试 |
|---|---|---|
| 不支持的来源类型、无法解码 | `InvalidImageException` | 否 |
| 像素超过 `max_pixels` | `ImageTooLargeException` | 否 |
| driver 缺扩展 | `MissingExtensionException` | 否 |
| 输出格式 driver 不支持 | `UnsupportedFormatException` | 否 |
| 预设名未配置 | `InvalidImageException` | 否 |

## 常用 API 速查

| 方法 | 说明 |
|---|---|
| `ImageManager::open(mixed $source)` | 解码并返回 Editor |
| `ImageManager::create($w, $h, $bg)` | 纯色画布 |
| `ImageEditor::resize($w, $h, $mode)` | contain / cover / scale 等 |
| `ImageConfig::presets()` / `preset($name)` | `ImagePresetsConfig` / `ImagePresetConfig` |
| `ImageEditor::applyPreset($name)` | 按配置名应用预设 |
| `ImageEditor::applyPresetConfig($preset)` | 直接应用 `ImagePresetConfig`（可代码构造） |
| `ImageEditor::encode(?EncodeOptions)` | 返回 `ProcessResult`（binary、mime、extension、size） |
| `ImageEditor::toBinary` / `toBase64` | 便捷输出 |
| `ImageEditor::size()` | 当前 `ImageSize` |
| `ImageConfig::watermarkImage()` / `watermarkText()` | 返回 `ImageWatermarkConfig` / `TextWatermarkConfig` |
| `ImageWatermark::fromWatermarkConfig($cfg)` | 从 typed 配置构建 |
| `ImageWatermark::fromConfig($imageConfig, $overrides)` | 便捷：读配置 + 覆盖 |
| `TextWatermark::fromWatermarkConfig` / `fromConfig` | 同上 |

## 非目标（v1）

- 不做 GIF 多帧编辑、视频首帧
- 不做 OCR / 人脸识别
- 不做 EXIF 全量解析（仅 orient + 可选 strip）

## 测试

```bash
./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Image
```

真编解码用例标 `@group image`，无 `ext-gd` 时会 skip。纯函数（`SizeCalculator`、`FormatNegotiator`、`ImageConfig`）不依赖扩展。
