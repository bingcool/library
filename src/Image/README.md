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

> `ImageManager` 只缓存 **driver 对应的 Intervention ImageManager** 与 `ImageConfig`，**不缓存**任何已解码图像。构造时只校验配置取值（`driver` 拼写等）；首次 `open()` / `create()` 才检测 `ext-gd` / `ext-imagick`，未使用图像的应用仍可正常启动。

## 依赖与 driver

| 包 / 扩展 | 说明 |
|---|---|
| `intervention/image ~4.4` | Composer **硬依赖**，构造时不做 `class_exists` 探测 |
| `ext-gd` **或** `ext-imagick` | 与 `image.driver` 对应，**至少装一个**才能 `open()` |

| driver | Intervention Driver | 格式能力（摘要） |
|---|---|---|
| `gd`（默认） | `Drivers\Gd\Driver` | jpeg、png、webp、gif 等；**无** avif |
| `imagick` | `Drivers\Imagick\Driver` | 含 avif 等（视 ImageMagick 编译选项） |

上表是静态白名单，实际可用格式还会按运行时能力过滤：GD 查 `gd_info()` 的 `JPEG/PNG/WebP/GIF Create/AVIF Support`，Imagick 查 `Imagick::queryFormats()`。例如 PHP 未编译 WebP 时，`webp` 会从可用列表中消失并抛 `UnsupportedFormatException`。

`driver` 只接受 `gd` / `imagick`；写错（如 `imagik`）在构造 `ImageManager` 时抛 `InvalidConfigException`，不再静默回退。扩展是否安装仍在首次 `open()` / `create()` 检测。

## 配置

`Config/image.php` 整文件由 `ImageManager` 注入，`ImageConfig` 只读解析。各段**作用**概览：

| 配置段 | 作用（解决什么问题） | 何时生效 |
|---|---|---|
| `driver` | 选定 PHP 用 GD 还是 Imagick 做解码/编码，适配不同机器环境 | 构造时校验取值；首次 `open()` / `create()` 检测扩展 |
| `max_pixels` | 拒绝超大图进内存，防止用户上传千万像素图拖垮 Swoole Worker | 解码/创建前像素预检，以及 resize/cover/crop/水印/overlay 的目标尺寸校验 |
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
         * 说明：非法值在构造时抛 InvalidConfigException；未装对应扩展时在首次 open/create 抛 MissingExtensionException。
         */
        'driver' => env('IMAGE_DRIVER', 'gd'),

        /*
         * base_path
         * 作用：相对路径的统一基准目录（默认 APP_PATH，再退化为进程 CWD）。
         * 场景：watermark.image.path / watermark.text.font 写相对路径时不再受 CWD 影响。
         */
        // 'base_path' => APP_PATH,

        /*
         * max_pixels
         * 作用：限制单张图解码后的最大像素（宽×高），作为上传/处理链路的安全阀。
         * 场景：用户上传相机原图、恶意大图时尽早失败，而不是占满 Worker 内存。
         * 说明：路径来源 fopen 后基于文件句柄预检（缩小 TOCTOU 窗口），解码后再复核一次；0=不限制（不推荐生产使用）。
         */
        'max_pixels' => 40_000_000,

        /*
         * encode
         * 作用：定义「保存/输出」时的默认格式与质量，供接口直出、写 OSS、生成缓存文件共用。
         * 优先级：全局 encode < preset 内 encode < encode(new EncodeOptions(...))。
         */
        'encode' => [
            // 作用：默认存什么格式（影响体积、透明通道、浏览器兼容）。gd 不支持 avif。
            // 说明：显式设置后即为全局默认；不配置时才按源图透明通道自动选 png/jpeg。
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
                // 作用：允许读取 logo 的根目录白名单；默认仅 base_path，
                //       防止配置被篡改时读取任意本地文件（LFI）。
                // 'allowed_roots' => [APP_PATH . '/Resource/watermark'],
            ],

            /*
             * watermark.text
             * 作用：叠加版权、站点名、用户 ID、拍摄日期等文字（内网图、凭证图、防泄露追踪）。
             */
            'text' => [
                // 作用：默认水印文案；按用户/订单动态改时用 fromConfig(..., ['content' => '...'])。
                'content'  => 'swoolefy',
                // 作用：指定渲染字体；中文环境需部署含汉字的 TTF。相对路径以 base_path 为基准。
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
| `path` | Logo 文件路径（PNG 透明底常用）；经 `realpath()` 校验存在且可读 |
| `position` | 九宫格锚点，如 `bottom-right`、`center`、`top-left`（Intervention `Alignment` 支持的写法） |
| `offset_x` / `offset_y` | 相对锚点的边距（像素） |
| `opacity` | 0~1，**越大越不透明**（如 `0.6` 为半透明 Logo） |
| `allowed_roots` | 允许读取 logo 的根目录白名单；`ImageConfig::watermarkImage()` 未配置时默认注入 `[base_path]`，空数组表示不额外限制（仅直接 `new ImageWatermark()` 时生效） |

> 相对路径以 `base_path` 为基准（默认 `APP_PATH`，再退化为进程 CWD）；路径中任何 `..` 段都会被拒绝，解析后还必须位于 `allowed_roots` 内，避免配置注入演变为任意本地文件读取。

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

配置 → `ImageWatermark`（`realpath` + 拒绝 `..` + `allowed_roots` 白名单）→ `watermarkImage()`：

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
| `font` | TTF/OTF 路径，须存在且可读；相对路径以 `base_path` 为基准 |
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
| `contain` | 等比缩进框内，**只缩不放、不补白**（小图保持原尺寸） |
| `cover` | 等比铺满框，居中裁剪（方图 thumb / avatar）；宽高都必须 > 0 |
| `scale` | 等比缩放（允许放大）；单维时按该维等比 |
| `stretch` / `fill` | 双维时强制拉到指定宽高；单维时退化为等比缩放 |

> `contain` / `scale` / `stretch` / `fill` 至少需要一维 > 0；未知 mode 直接抛 `InvalidImageException`，不再静默回退 contain。

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

全局 `encode.format` 若为 `jpeg`，而 `avatar` 预设带 `'encode' => ['format' => 'webp']`，则 **`applyPreset('avatar')` 之后** 的 `encode()` 默认用 webp；仍可在本次 `encode(new EncodeOptions(...))` 中再覆盖。preset 的 `encode` 同样支持 `max_bytes`，会覆盖全局值。

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

#### 默认格式、质量与 `strip`

| 情况 | 行为 |
|---|---|
| 任一层显式配置了 `format` | 使用该格式（须为当前 driver 与运行时所支持） |
| 未配置 `format`，且源图为 png / webp / avif / gif | 默认 `png`，避免透明通道被 JPEG 压平 |
| 未配置 `format`，其余来源（含 `create()`） | 默认 `jpeg` |
| `quality` | 必须为 0–100，越界在入口抛 `InvalidImageException`（不再依赖编码器报错） |
| GIF + `strip=true` | `UnsupportedFormatException`；`GifEncoder` 无 strip 能力，不再静默忽略 |

> 需要绝对可控时，请显式传 `EncodeOptions(format: ...)`；自动默认只用于「没配置也不该丢透明」的兼容场景。

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
| `driver` 不是 `gd` / `imagick` | `InvalidConfigException`（构造 `ImageManager` 时） |
| Logo `path` 空、不存在、含 `..` | `InvalidImageException` |
| Logo 不在 `allowed_roots` 内 | `InvalidImageException` |
| 文字 `content` 空或 `font` 不可读 | `InvalidImageException` |
| `applyPreset('xxx')` 但配置无 xxx | `InvalidImageException` |
| gd driver 下要求 avif | `UnsupportedFormatException` |
| 请求 bmp/tiff 等无 encoder 的格式 | `UnsupportedFormatException` |
| 原图像素超过 `max_pixels` | `ImageTooLargeException`（解码前） |
| 水印 logo / overlay 像素超过 `max_pixels` | `ImageTooLargeException` |
| resize/cover/crop 目标尺寸超过 `max_pixels` | `ImageTooLargeException`（分配内存前） |
| `resize(0, 0)`、cover 单维、未知 mode | `InvalidImageException` |
| `thumbnail(0, 0)`（两维都非正） | `InvalidImageException` |
| `quality` 不在 0–100 | `InvalidImageException` |
| GIF 编码传 `strip=true` | `UnsupportedFormatException` |

---

## 使用

### 打开来源与解码

`open(mixed $source)` 识别顺序：

| 来源 | 行为 |
|---|---|
| `\SplFileInfo` | 取 `getPathname()` 后走「文件路径」分支 |
| PHP `resource`（流） | 先 `rewind()`；可 seek 流读头部（最多 1MB）预检后 `decodeStream`；不可 seek 流先复制到 `php://temp` 再预检 |
| 存在且为**文件**的 string | `fopen('rb')` 后用**文件句柄**预检并 `decodeStream`，再回填 `origin()->setFilePath()`；避免 `getimagesize($path)` 与解码之间的文件替换窗口（TOCTOU） |
| 其他 string | 按**二进制** `decodeBinary`，解码前 `getimagesizefromstring()` 预检 |

所有来源在解码后还会复核一次宽高上限（兜底）；水印 logo、`overlayCenter()` 与 `overlayCenterImage()` 的叠加图同样受 `max_pixels` 约束。

Intervention v4 解码器默认 `autoOrientation=true`，EXIF 方向在解码时已校正一次，`ImageManager` 不再额外调用 `orient()`。`ImageException` 子类（如 `ImageTooLargeException`）**原样抛出**，其余解码失败包装为 `InvalidImageException`。

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

`ImageEditor::overlayCenter()` / `encodePngBinary()` 供 Qrcode 模块在 PNG 码图正中叠加 Logo；业务代码一般只通过 `QrcodeBuilder::withLogo()` 使用。批量叠加时可用 `overlayCenterImage()` 直接传入已解码的 `ImageInterface`，避免重复 IO。

## 异常与是否重试

| 场景 | 异常 | Job 是否重试 |
|---|---|---|
| `driver` 不是 `gd` / `imagick` | `InvalidConfigException` | 否 |
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
| `ImageEditor::encodePngBinary()` | 等价于 `encode(new EncodeOptions(format: 'png'))`，受统一选项与 `max_bytes` 约束 |
| `ImageEditor::overlayCenter($path, $w, $h, $opacity)` | 解码路径并居中叠加 |
| `ImageEditor::overlayCenterImage($image, $w, $h, $opacity)` | 复用已解码 overlay，避免重复 IO |
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
