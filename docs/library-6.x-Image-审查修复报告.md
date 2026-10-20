# library 6.x Image 模块审查修复报告

| 项目 | 内容 |
|---|---|
| 审查日期 | 2026-10-20 |
| 审查对象 | `src/Image`（审查时 23 个源文件，修复后 25 个）及 `src/Qrcode/Support/LogoApplier.php` 调用链 |
| 依赖版本 | `intervention/image` 4.4.x（GD / Imagick 双驱动） |
| 运行环境 | PHP 8.4.14、GD 2.x、Imagick 扩展 |
| 审查方式 | 静态审查 + 最小用例运行时复现 + PHPUnit 基线 |
| 代码基线 | 当前工作区；P0/P1/P2 修复已落地（`src/Image` 14 个源文件 + 3 个新测试文件） |
| 修复状态 | **已修复 36/36（P0 全部、P1 全部、P2 全部、D 全部）** |

> 说明：本报告中的「是否修复」以 2026-10-20 修复后的代码为准。所有结论均已对照 `vendor/intervention/image` 4.4.x 源码确认；标注「实测」的条目已在 PHP 8.4 + GD/Imagick 下复现。P0-1 ~ P0-4 修复前数据见 §8.4、修复后复验见 §8.5；P1-1 ~ P1-9 及其连带关闭的 P2-3、P2-6、D-1、D-3、D-6 见 §8.6；P2-1 ~ P2-17 见 §8.7。

---

## 1. 审查结论

1. **问题集中在 v3 → v4 的语义迁移不彻底**。审查时水印定位与透明度仍按 v3 语义实现，导致水印链路整体不可用；P0 与 P1 均已于 2026-10-20 修复（见 §8.5、§8.6）。
2. **P0 共 4 项，均为功能性错误（已全部修复）**：图片水印除 `top-left` 外全部错位；`overlayCenter()` 不居中且默认完全透明，直接导致 Qrcode 的 `withLogo()` Logo 不可见；图片水印透明度反转；文字水印 alpha 比例错误且部分颜色格式非法。
3. **P1 共 9 项，集中在行为正确性与安全边界**：`contain()` 补白、encode 三级优先级失效、preset 编码残留、bmp/tiff 静默输出 JPEG、二进制解码前无像素预检、resize 退化参数、目标尺寸可绕过 `max_pixels`、preset `max_bytes` 失效、Intervention 异常泄漏；已全部于 2026-10-20 修复。
4. **P2 共 17 项**，多为参数校验、路径语义、能力检测、死代码与一致性清理，不阻断主流程但会放大线上排障成本；已全部于 2026-10-20 修复（见 §8.7）。
5. **文档与测试 6 项**：README 中 `max_pixels`、encode 优先级的描述与实现不符；设计文档曾按 v3 编写、示例缺少必填参数，该问题已于 2026-10-20 修正（D-5）。P0 修复新增 `tests/Image/WatermarkPixelTest.php`（8 个用例），P1 修复新增 `tests/Image/P1RegressionTest.php`（20 个用例），P2 修复新增 `tests/Image/P2RegressionTest.php`（19 个用例）；`tests/Image` 共 55 个用例、218 个断言全部通过，Image + Qrcode 共 62 个用例、227 个断言全部通过。D-1/D-3/D-6 已随 P1 修复同步关闭。

### 1.1 问题统计

| 级别 | 数量 | 说明 |
|---|---:|---|
| P0 | 4 | 功能错误，必须优先修复；已全部修复 |
| P1 | 9 | 行为/安全缺陷；已全部修复 |
| P2 | 17 | 健壮性、一致性、可维护性优化；已全部修复 |
| 文档与测试 | 6 | 已全部修复 |
| 合计 | 36 | 已修复 36，未修复 0 |

---

## 2. 问题总览

### 2.1 P0（阻断）

| 编号 | 问题 | 位置 | 建议 | 是否修复 |
|---|---|---|---|---|
| P0-1 | 图片水印九宫格定位错位 | `ImageEditor::watermarkImage()`、`PositionAnchor::insertOffsets()` | 新增 `insertOffsets()` 与 `resolve()` 分离两套坐标 | 是（2026-10-20） |
| P0-2 | `overlayCenter()` 不居中，且默认 opacity=0 完全透明 | `ImageEditor::overlayCenter()`、`LogoApplier::apply()` | 改为 `insert($overlay, 0, 0, CENTER, 1.0)` | 是（2026-10-20） |
| P0-3 | 图片水印不透明度反转 | `ImageEditor::watermarkImage()` | 直接传 `opacity`，不再取反 | 是（2026-10-20） |
| P0-4 | 文字水印 alpha 比例错误、颜色拼接非法 | `ImageEditor::watermarkText()`、`textColor()` | `Rgb\Color::parse()` + `Color::create()->toHex()` | 是（2026-10-20） |

### 2.2 P1（高）

| 编号 | 问题 | 位置 | 建议 | 是否修复 |
|---|---|---|---|---|
| P1-1 | `contain()` 补白到目标画布，小图被放大，与文档语义不符 | `ImageEditor::resize()` | 改用 `scaleDown()`；如需补白另立 `pad` 模式 | 是（2026-10-20） |
| P1-2 | `encode()` 三级优先级失效，`EncodeOptions` 覆盖 preset 全部字段 | `ImageEditor::encode()`、`EncodeOptions::merge()` | `merge()` 改为可变参数逐级合并 | 是（2026-10-20） |
| P1-3 | 连续应用 preset 时 `presetEncode` 残留 | `ImageEditor::applyPresetConfig()` | 每次进入先重置为 null | 是（2026-10-20） |
| P1-4 | bmp/tiff 通过白名单但落入 JpegEncoder，extension/mime 不一致 | `FormatNegotiator`、`ImageEditor::encode()` | 移除无 encoder 的格式；encoder 分支显式化 | 是（2026-10-20） |
| P1-5 | 二进制/流解码前无像素预检；水印/overlay 解码绕过 PixelGuard | `ImageManager::open()`、`ImageEditor` | 增加 `getimagesizefromstring()` 预检；水印统一走受检解码 | 是（2026-10-20） |
| P1-6 | resize 退化参数产生异常输出 | `ImageEditor::resize()` | 入口校验，单维改用等比缩放 | 是（2026-10-20） |
| P1-7 | resize/cover/crop 目标尺寸不受 `max_pixels` 约束 | `ImageEditor` | 每次几何操作前按目标尺寸校验 | 是（2026-10-20） |
| P1-8 | preset 的 `encode.max_bytes` 被忽略 | `ImageEditor::encode()` | `EncodeOptions` 增加 `maxBytes` 并纳入合并 | 是（2026-10-20） |
| P1-9 | Intervention 异常泄漏到公共 API | `ImageEditor`、`ImageManager::create()` | 边界统一包装为库异常 | 是（2026-10-20） |

### 2.3 P2（优化）

| 编号 | 问题 | 位置 | 建议 | 是否修复 |
|---|---|---|---|---|
| P2-1 | 非法 `driver` 静默回退 gd | `ImageConfig::driver()` L34-39 | 抛配置异常或记录 warning | 是（2026-10-20） |
| P2-2 | `quality` 未 clamp，越界依赖 encoder 抛原始异常 | `ImageEditor::encode()` L237 | 入口 clamp 0–100 并校验 | 是（2026-10-20） |
| P2-3 | `mode` 未 trim、未校验，未知值静默走 contain | `ImageEditor::resize()` L62 | 白名单校验，未知值抛异常 | 是（2026-10-20，随 P1-6） |
| P2-4 | `thumbnail(0, 0)` 未校验，抛底层异常 | `ImageEditor::thumbnail()` L91-96 | 要求至少一维 >0 | 是（2026-10-20） |
| P2-5 | 默认 jpeg 会压平透明 PNG（黑底/白底） | `ImageEditor::encode()` L236 | 透明图默认 png，或显式填充背景 | 是（2026-10-20） |
| P2-6 | `create()` 非法颜色异常未包装 | `ImageManager::create()` L107 | 捕获并包装为 `InvalidImageException` | 是（2026-10-20，随 P1-9） |
| P2-7 | 水印/字体相对路径基于 CWD，README 却写 APP_PATH | `ImageWatermark::resolvePath()` L61-73、`TextWatermark` L40 | 统一基于项目根目录解析 | 是（2026-10-20） |
| P2-8 | 水印路径允许任意本地文件，且无像素限制 | `ImageWatermark::resolvePath()` L61-73 | 增加允许根目录与尺寸预检 | 是（2026-10-20） |
| P2-9 | `overlayCenter()` 每次重新解码 logo | `ImageEditor::overlayCenter()` L289 | 由调用方复用已解码实例 | 是（2026-10-20） |
| P2-10 | `SizeCalculator` 生产代码未使用，且 contain 语义与 Editor 不一致 | `Support/SizeCalculator.php` L20-53 | 删除或让 Editor 复用 | 是（2026-10-20） |
| P2-11 | 未做运行时能力检测 | `FormatNegotiator` L15-18 | 结合 `gd_info()` / `Imagick::queryFormats()` | 是（2026-10-20） |
| P2-12 | `PixelGuard::assertFilePath()` 存在 TOCTOU | `Support/PixelGuard.php` L43-55 | 解码后复核尺寸，或基于文件句柄校验 | 是（2026-10-20） |
| P2-13 | presets 数字键导致 `names()` 返回 int | `ImagePresetsConfig::names()` L57-60 | 统一 `array_map('strval', ...)` | 是（2026-10-20） |
| P2-14 | `decodeStream()` 前未 rewind，受当前指针影响 | `ImageManager::open()` L68-69 | 可 seek 时先 `rewind()` | 是（2026-10-20） |
| P2-15 | `open()` 显式 `orient()` 冗余 | `ImageManager::open()` L85 | 依赖 v4 默认 autoOrientation，或关闭默认后显式处理 | 是（2026-10-20） |
| P2-16 | GIF 编码忽略 `strip` 参数 | `ImageEditor::encode()` L244 | 文档标注不支持，或改用统一编码参数 | 是（2026-10-20） |
| P2-17 | `encodePngBinary()` 绕过 max_bytes/异常包装 | `ImageEditor::encodePngBinary()` L298-301 | 内部统一走 `encode()` 或补充校验 | 是（2026-10-20） |

### 2.4 文档与测试

| 编号 | 问题 | 位置 | 建议 | 是否修复 |
|---|---|---|---|---|
| D-1 | `max_pixels` 被描述为「编码后体积校验」 | `src/Image/README.md` L44 | 改为「解码前像素预检」 | 是（2026-10-20） |
| D-2 | `opacity` 描述「越大越不透明」与实现相反 | README L253、L115/L132 | 修复代码后保持文档语义 | 是（2026-10-20，随 P0-3 对齐） |
| D-3 | encode 三级优先级文档与实现不符 | README L403、L426-443 | 实现三级合并后补测试 | 是（2026-10-20，随 P1-2） |
| D-4 | `overlayCenter()` 声称居中，实际不居中且不可见 | README L580 | 修复后补像素级测试 | 是（2026-10-20，随 P0-2 对齐） |
| D-5 | 设计文档曾按 Intervention v3 编写，示例缺 `font` | `docs/library-6.x-Image图像处理技术方案.md` | 已更新为 4.4.x（v4）语义并修正示例 | 是（2026-10-20） |
| D-6 | 测试未覆盖关键链路 | `tests/Image/` | 补水印/overlay/contain/encode 合并/bmp-tiff/二进制防护 | 是（2026-10-20） |

---

## 3. P0 详情

### P0-1 图片水印九宫格定位错位

**位置**：修复前 `src/Image/ImageEditor.php:146-165`、`src/Image/Support/PositionAnchor.php:23-92`；修复后 `ImageEditor.php:148-161` 改为调用新增的 `PositionAnchor::insertOffsets()`（L31）

**现象**：`watermarkImage()` 除 `top-left` 外，logo 均出现在错误位置。`bottom-right` 时 logo 跑到画布左上并被裁切。

**根因**：

- v4 `insert($image, $x, $y, $alignment, $transparency)` 中，`$x/$y` 是**相对于 `$alignment` 锚点的偏移**，不是绝对坐标。底层 `Size::movePivot()` 对右侧/底部执行 `width - x`、`height - y`。
- `PositionAnchor::resolve()` 返回的是**绝对坐标**（如 `top-right` 返回 `width - offsetX`），被 `insert()` 再按锚点减一次，形成二次偏移。
- 同一 helper 同时服务 `insert()` 与 `text()`，但两者语义不同：`text()` 的 position 是绝对锚点，配合 `font->align()` 使用，现有绝对坐标对文字是正确的。

**实测**：100×100 画布叠加 40×40 logo，`bottom-right`、`offset=16`。期望 logo 左上角 `(44,44)`、右下角距边 16px；实际插入左上角为 `(-24,-24)`，可见部分被裁到画布左上角 `(0,0)` 附近。九宫格完整复验（200×120 画布）：**仅 `top-left` 正确，其余 8 个位置全部错位**，数据见 §8.4。

**建议**：

1. 拆分两套解析：`PositionAnchor::resolveForInsert()` 返回偏移量；`resolveForText()` 保留现有绝对锚点逻辑。
2. `watermarkImage()` 改用偏移量，右侧/底部方向的正数表示向内缩。
3. 删除 `resolveForInsert()` 对 `width/height` 的依赖，减少参数耦合。

```php
// PositionAnchor::resolveForInsert()：只返回相对锚点的内缩量
$horizontalOffset = in_array($alignment, [
    Alignment::TOP, Alignment::CENTER, Alignment::BOTTOM,
], true) ? 0 : $offsetX;

$verticalOffset = in_array($alignment, [
    Alignment::LEFT, Alignment::CENTER, Alignment::RIGHT,
], true) ? 0 : $offsetY;

return ['x' => $horizontalOffset, 'y' => $verticalOffset, 'alignment' => $alignment];
```

**是否修复**：是（2026-10-20）。实现时命名为 `PositionAnchor::insertOffsets()`，`resolve()` 保留给 `text()`，两套坐标彻底分离。

**验收**：九宫格 9 个位置逐一断言 logo 像素区域；`bottom-right`/16px 时右下角距边 16px。

---

### P0-2 `overlayCenter()` 不居中，且默认完全透明

**位置**：修复前 `src/Image/ImageEditor.php:287-295`、`src/Qrcode/Support/LogoApplier.php:42`；修复后 `ImageEditor.php:282-300`（默认 `opacity=1.0`）

**现象**：

- overlay 实际落在画布右下角，而不是中心。
- `$opacity` 默认值为 `0`，在 v4 下等于完全透明，画布没有任何变化。
- 直接后果：`QrcodeBuilder::withLogo()` 生成的二维码不含可见 Logo。

**根因**：

1. 代码先算出「左上角绝对坐标」`(W - targetW) / 2`，再以 `Alignment::CENTER` 插入。`insert()` 会把该坐标当作相对中心的偏移，最终位置为 `W/2 + x - targetW/2`。
2. v4 `insert()` 的 `$transparency` 是**不透明度因子**：`1.0` 为原图，`0.0` 为全透明；GD 实现为 `newAlpha = 127 - round((127 - alpha) * transparency)`。

**实测**：200×200 画布、40×40 overlay。期望左上角 `(80,80)`，实际左上角 `(160,160)`（右下角）；默认 `opacity=0` 时输出与未叠加完全一致。

**建议**：

```php
public function overlayCenter(
    string $overlayPath,
    int $targetWidth,
    int $targetHeight,
    float $opacity = 1.0,
): self {
    $overlay = $this->manager->decodePath($overlayPath)
        ->cover($targetWidth, $targetHeight);

    $this->image = $this->image->insert(
        $overlay,
        0,
        0,
        Alignment::CENTER,
        max(0.0, min(1.0, $opacity)),
    );

    return $this;
}
```

同时给 `LogoApplier::apply()` 补一条中心像素断言，避免回归。

**是否修复**：是（2026-10-20）。`LogoApplier` 无需改动，随 `overlayCenter()` 默认值修正自动可见。

**验收**：中心像素等于 logo 颜色；`withLogo()` 输出与无 logo 输出在中心区域存在差异。

---

### P0-3 图片水印不透明度反转

**位置**：修复前 `src/Image/ImageEditor.php:155`；修复后 `ImageEditor.php:151-158`

**现象**：配置 `opacity=0.6` 实际约为 40% 不透明；`opacity=1.0` 时水印完全不可见。

**根因**：

```php
$opacity = max(0.0, min(1.0, 1.0 - $mark->opacity));
```

v3 使用 `opacity` 表示透明度，v4 的 `transparency` 参数实际是「不透明度因子」，代码多做了一次取反。

**实测**：`opacity=1.0` → 传入 `0.0` → 全透明；`opacity=0.6` → 传入 `0.4` → 约 40% 不透明。

**建议**：

```php
$opacity = max(0.0, min(1.0, $mark->opacity));
```

并同步修正 `ImageWatermark` 的构造函数注释（当前已写「越大越不透明」，与实现相反）。

**是否修复**：是（2026-10-20）。

**验收**：`opacity=1.0` 时 logo 原始像素不被淡化；`opacity=0.5` 时采样像素约为原始值的一半。

---

### P0-4 文字水印 alpha 比例错误、颜色拼接非法

**位置**：修复前 `src/Image/ImageEditor.php:177-178`；修复后 `ImageEditor.php:174` 调用新增的 `textColor()`（L305-327）

**现象**：

- `opacity=1.0` 实际不透明度约 39%；`opacity=0.5` 实际约 20%。
- `color='#fff'` 会拼成 `#fffXX`，`color='red'` 会拼成 `redXX`，均为非法颜色（实测分别抛 `Intervention\Image\Exceptions\InvalidArgumentException: Hex color has an invalid format` 与 `ColorDecoderException: Unknown color format`，都不是 `ImageException`，同时印证 P1-9）。

**根因**：

```php
$alpha = (int) round((1.0 - max(0.0, min(1.0, $mark->opacity))) * 100);
$color = $mark->color . sprintf('%02x', max(0, min(100, 100 - $alpha)));
```

1. v4 的 8 位 hex `#RRGGBBAA` 中 alpha 范围是 **0–255**，不是 0–100。
2. 直接字符串拼接要求颜色必须是 6 位 hex，未兼容 3 位 hex 与命名颜色。

**实测**：

- `opacity=1.0` → `#ffffff64` → alpha 100/255 ≈ 0.39。
- `opacity=0.5` → `#ffffff32` → alpha 50/255 ≈ 0.20。

**建议**：使用 v4 自有颜色 API，兼容命名色、3/4/6/8 位 hex，并统一由 alpha 通道生成：

```php
use Intervention\Image\Colors\Rgb\Color;

$rgb = Color::parse($mark->color); // 支持 red、#fff、#ffffff、#ffffff80
$color = Color::create(
    $rgb->red()->value(),
    $rgb->green()->value(),
    $rgb->blue()->value(),
    max(0.0, min(1.0, $mark->opacity)),
)->toHex(prefix: true);
```

同时建议在 `TextWatermark` 构造时校验 `color`、`size > 0`、`angle` 范围，避免运行到绘制阶段才失败。

**是否修复**：是（2026-10-20）。`textColor()` 用 `Color::parse()` 兼容 `#fff` / 命名色，再交给 `Color::create(..., $opacity)->toHex()`；非法颜色包装为 `InvalidImageException`。

**验收**：`opacity=1.0` / `0.5` 时文字像素 alpha 约为 255 / 128；`#fff`、`red` 均可正常渲染。

---

## 4. P1 详情

### P1-1 `contain()` 语义与文档不符

**位置**：`src/Image/ImageEditor.php:85`；README L165、L174；设计文档 L233-244

**现象**：

- 100×50 图 `resize(400, 400, 'contain')` 输出 400×400，四周白底。
- 小图会被放大到目标框。
- 单维 `contain` 走 `scaleDown()` 不补白，双维走 `contain()` 补白，同一模式行为不一致。

**根因**：v4 `contain()` 是「固定目标画布 + 居中 + 背景填充」，v3 的 `resize`/`widen` 语义是「等比缩进、不补白」。设计文档与 README 仍按 v3 描述。

**建议**：

- `contain` 默认改为 `scaleDown($w, $h)`，实现「等比、只缩不放、不补白」。
- 若业务确需固定画布，新增 `pad` 模式，显式接收背景色与是否放大，避免默认行为带白边。
- 单维/双维统一走同一分支，`null`/`0` 表示该维不限制。

**是否修复**：是（2026-10-20）

**验收**：1000×500 + `contain(400,400)` → 400×200（缩小、不补白）；100×50 + `contain(400,400)` → 100×50（不放大）；20×10 + `contain(400,400)` → 20×10。

---

### P1-2 `encode()` 三级优先级失效

**位置**：`src/Image/ImageEditor.php:235`；README L403、L426-443

**现象**：全局 `jpeg` + preset `png` + `encode(new EncodeOptions(quality: 90))`，最终输出 JPEG，preset 的 `format=png` 丢失。

**根因**：`$options ?? $this->presetEncode` 是二选一。传入 `$options` 后 preset 被整体丢弃，而非逐字段合并。

**建议**：把 `EncodeOptions::merge()` 改为可变参数，按顺序逐级覆盖：

```php
public static function merge(array $defaults, ?self ...$overrides): self
{
    $merged = new self(
        isset($defaults['format']) ? (string) $defaults['format'] : null,
        isset($defaults['quality']) ? (int) $defaults['quality'] : null,
        isset($defaults['progressive']) ? (bool) $defaults['progressive'] : null,
        isset($defaults['strip']) ? (bool) $defaults['strip'] : null,
    );

    foreach ($overrides as $override) {
        if ($override === null) {
            continue;
        }
        $merged = new self(
            $override->format ?? $merged->format,
            $override->quality ?? $merged->quality,
            $override->progressive ?? $merged->progressive,
            $override->strip ?? $merged->strip,
        );
    }

    return $merged;
}
```

> `maxBytes` 字段在 P1-8 中扩展后，再在构造与合并逻辑中追加一行。

`encode()` 中调用 `EncodeOptions::merge($this->config->encodeDefaults(), $this->presetEncode, $options)`。

**是否修复**：是（2026-10-20）

**验收**：覆盖「全局 + 无 preset + 无 options」「全局 + preset」「全局 + preset + options」三种组合。

---

### P1-3 连续应用 preset 时 `presetEncode` 残留

**位置**：`src/Image/ImageEditor.php:207-223`

**现象**：先 `applyPreset('avatar')`（带 webp），再 `applyPreset('list')`（无 encode），最终仍输出 webp。

**根因**：`applyPresetConfig()` 只在 `$encode !== null` 时赋值，无 encode 的 preset 不会清空上一次的值。

**建议**：进入方法先 `$this->presetEncode = null;`，再按当前 preset 的 encode 赋值。

**是否修复**：是（2026-10-20）

**验收**：连续应用两个 preset 后，encode format 等于第二个 preset（或全局默认）。

---

### P1-4 bmp/tiff 通过白名单但输出 JPEG

**位置**：`src/Image/Support/FormatNegotiator.php:18`、`src/Image/ImageEditor.php:241-247`、L308-328

**现象**：Imagick 下 `encode(new EncodeOptions(format: 'bmp'))` 不报错，但输出字节 magic 为 `ffd8ffe0`（JPEG），`ProcessResult` 却返回 `extension=bmp`、`mime=image/jpeg`。

**根因**：

- 白名单包含 `tiff`、`bmp`，但 `match` 中没有对应 encoder，落入 `default => JpegEncoder`。
- `extension` 按请求 format 推导，`mime` 按 encoder 输出推导，两者来源不一致。

**建议**（二选一）：

1. v1 明确不支持 bmp/tiff：从 `FormatNegotiator::IMAGICK` 中移除，让 `assertSupported()` 直接抛 `UnsupportedFormatException`。
2. 确实需要支持：新增 `BmpEncoder`、`TiffEncoder` 分支，并让 `extension`/`mime` 均从最终 encoder 推导。

**是否修复**：是（2026-10-20）

**验收**：不支持的格式抛库异常；支持的格式 magic、extension、mime 三者一致。

---

### P1-5 二进制/流无解码前像素预检，水印解码绕过 PixelGuard

**位置**：`src/Image/ImageManager.php:68-74`、L84；`src/Image/ImageEditor.php:289`

**现象**：

- 路径来源会在解码前 `getimagesize()` 预检；二进制（`decodeBinary`）与流（`decodeStream`）先解码、后校验，压缩炸弹可在校验前耗尽 Worker 内存。
- 水印 `ImageWatermark` 与 `overlayCenter()` 直接调用 `decodePath()`，完全绕过 `max_pixels`。
- 水印图片本身也没有任何尺寸上限。

**建议**：

1. 二进制来源先 `getimagesizefromstring()` 取宽高，再 `assertWithinLimit()`，然后解码。
2. 流来源先读取少量头部做 `getimagesizefromstring()`，或复制到临时流后预检；无法预检时至少保留解码后复核。
3. `overlayCenter()` 与 `watermarkImage()` 统一走 `ImageManager::open()` 或显式 `PixelGuard::assertFilePath()`。
4. 保留 `open()` 解码后的复核作为兜底。

**是否修复**：是（2026-10-20）

**验收**：构造高压缩比超大图二进制，断言在解码前抛 `ImageTooLargeException`；超限 logo 被拒绝。

---

### P1-6 resize 退化参数产生异常输出

**位置**：`src/Image/ImageEditor.php:60-88`

**现象**：

| 调用 | 当前结果 | 期望 |
|---|---|---|
| `resize(20, null, 'scale')` | 20×50（拉伸变形） | 等比缩放到宽 20 |
| `resize(0, 0, 'contain')` | 1×1 | 参数异常 |
| `resize(0, 100, 'cover')` | 1×100 | 参数异常 |

**根因**：

- v4 `resize($w, $h)` 是宽高独立拉伸，不是等比。
- `max(1, $w ?? 1)` 把 0/null 静默变成 1。
- `cover` 未校验双维必须 >0。

**建议**：

- `cover`：宽高必须均 >0，否则抛 `InvalidImageException`。
- `contain`：至少一维 >0；单维走等比 `scaleDown()`。
- `scale`/`stretch`/`fill`：双维 >0 用 `resize()`；单维改用 `scale()` 或 `scaleDown()`，语义写入文档。
- 未知 mode 抛异常，不再静默走 contain。

**是否修复**：是（2026-10-20）

**验收**：`null`/`0`/负数参数矩阵单测，全部有确定行为。

---

### P1-7 目标尺寸不受 `max_pixels` 约束

**位置**：`src/Image/ImageEditor.php:60-111`

**现象**：原图 100×100（低于默认 4000 万像素），preset `[10000, 10000]` 可通过校验，resize 后为 1 亿像素，GD 分配内存时 OOM。

**根因**：`max_pixels` 只在 `open()` 解码后与 `create()` 时校验，编辑链的目标尺寸未校验。

**建议**：在 `resize()`、`cover()`、`crop()` 执行前，用目标宽高调用 `PixelGuard::assertWithinLimit()`；`cover` 的中间放大尺寸可能大于最终尺寸，按中间尺寸或最终尺寸取更严格者。也可在 `ImageEditor` 内提供统一的 `assertTargetSize()`。

**是否修复**：是（2026-10-20）

**验收**：`max_pixels=1_000_000` 时，preset 10000×10000 在分配内存前抛 `ImageTooLargeException`。

---

### P1-8 preset 的 `encode.max_bytes` 被忽略

**位置**：`src/Image/ImageEditor.php:251`

**现象**：preset 配置 `'encode' => ['max_bytes' => 1]`，输出仍为正常的 100+ 字节，没有抛 `ImageTooLargeException`。

**根因**：`max_bytes` 只从全局 `encodeDefaults()` 读取；`EncodeOptions` 也没有 `maxBytes` 字段，preset 值在 `encodeOptions()` 阶段被丢弃。

**建议**：`EncodeOptions` 增加 `?int $maxBytes`，`ImagePresetConfig::encodeOptions()` 透传，`encode()` 使用合并后的值。

**是否修复**：是（2026-10-20）

**验收**：preset `max_bytes=1` 时抛 `ImageTooLargeException`；全局值可被 preset 覆盖。

---

### P1-9 Intervention 异常泄漏

**位置**：`src/Image/ImageEditor.php:155/178/180/241`、`src/Image/ImageManager.php:107`

**现象**：以下场景抛出的都不是 `Swoolefy\Library\Image\Exception\ImageException`：

- `quality=200` → `Intervention\Image\Exceptions\InvalidArgumentException`
- 非法颜色 → `Intervention\Image\Exceptions\ColorDecoderException`
- 非法 `position` → `Intervention\Image\Exceptions\InvalidArgumentException`
- `create()` 非法背景色 → `ColorDecoderException`

调用方按文档只捕获 `ImageException` 时会漏捕，Swoole 协程中可能直接冒泡为 500。

**建议**：在公共 API 边界统一捕获 `Intervention\Image\Exception\ImageException`（v4 基类），包装为库异常并保留 `previous`；或在入口先做参数校验（quality、颜色、position、尺寸），让异常在进入 Intervention 前抛出。

**是否修复**：是（2026-10-20）

**验收**：上述四类非法输入均抛 `InvalidImageException` / `UnsupportedFormatException`，且 `getPrevious()` 保留原始异常。

---

## 5. P2 详情

| 编号 | 现状与影响 | 建议 | 是否修复 |
|---|---|---|---|
| P2-1 | `driver` 写错（如 `imagik`）静默用 GD，能力差异被隐藏 | 构造时校验，非法值抛配置异常；至少记录 warning | 是（2026-10-20） |
| P2-2 | `quality=200` 直到编码器才报错，异常类型不受控 | `encode()` 入口 `max(0, min(100, $quality))` 或抛库异常 | 是（2026-10-20） |
| P2-3 | `mode=' COVER '` 走 contain，拼写错误无感 | `trim` + 白名单校验，未知值抛异常 | 是（2026-10-20，随 P1-6） |
| P2-4 | `thumbnail(0, 0)` 抛 Intervention 原始异常 | 要求至少一维 >0，抛 `InvalidImageException` | 是（2026-10-20） |
| P2-5 | 透明 PNG 默认编码为 JPEG，透明区域变黑/白 | 检测 alpha 后默认 png，或要求显式指定背景色 | 是（2026-10-20） |
| P2-6 | `create()` 非法颜色抛 `ColorDecoderException` | 捕获并包装为 `InvalidImageException` | 是（2026-10-20，随 P1-9） |
| P2-7 | 相对路径基于进程 CWD，README 写的是 APP_PATH | 统一用项目根目录/配置根目录解析，并写入文档 | 是（2026-10-20） |
| P2-8 | `ImageWatermark` 接受任意可读本地图片，存在 LFI 面（像素上限已随 P1-5 补齐） | 增加允许根目录白名单；路径由业务白名单约束 | 是（2026-10-20） |
| P2-9 | `overlayCenter()` 每次重新解码 logo，批量场景重复 IO | 提供接收已解码 `ImageInterface` 的重载，或由调用方缓存 | 是（2026-10-20） |
| P2-10 | `SizeCalculator` 仅测试使用，且其 contain 语义与 Editor 不一致 | 删除死代码，或让 `ImageEditor::resize()` 复用以保证一致 | 是（2026-10-20） |
| P2-11 | 白名单是静态数组，未检测 GD/Imagick 实际编译能力 | 结合 `gd_info()`、`Imagick::queryFormats()` 做运行时过滤 | 是（2026-10-20） |
| P2-12 | `getimagesize()` 与解码之间存在文件替换窗口 | 解码后再次校验宽高；高安全场景基于文件句柄读取 | 是（2026-10-20） |
| P2-13 | `names()` 直接 `array_keys`，数字 preset 名返回 int，与 `list<string>` 不符 | `array_map('strval', array_keys(...))` | 是（2026-10-20） |
| P2-14 | 流来源不 rewind，调用方指针位置影响解码结果 | 可 seek 时先 `rewind()`，失败时明确抛异常 | 是（2026-10-20） |
| P2-15 | v4 默认 `autoOrientation=true`，`open()` 再 `orient()` 属重复处理 | 依赖默认行为，或显式关闭默认后统一处理一次 | 是（2026-10-20） |
| P2-16 | `strip` 传给 `GifEncoder` 但该类无此能力，参数被静默忽略 | 文档标注，或按格式分派参数并校验 | 是（2026-10-20） |
| P2-17 | `encodePngBinary()` 绕过 `max_bytes`、统一异常与选项合并 | 内部改用 `encode(new EncodeOptions(format: 'png'))`，保留专用方法时补校验 | 是（2026-10-20） |

> 实现细节、实测行为与测试结果见 §8.7。

---

## 6. 文档与测试问题

| 编号 | 问题 | 建议 |
|---|---|---|
| D-1 | README L44 将 `max_pixels` 描述为「编码后体积校验」，实际是解码前像素预检 | 改为「解码前宽×高上限；`max_bytes` 才是编码后体积」 |
| D-2 | README L253 写「opacity 越大越不透明」，实现却是取反（P0-3） | 修复 P0-3 后文档语义即成立，并补 `opacity=1` 示例 |
| D-3 | README L403/L426-443 描述三级 encode 优先级，实现只做两级二选一（P1-2） | 修复 P1-2 后补三种组合的断言 |
| D-4 | README L580 声称 `overlayCenter()` 在正中叠加，实际右下且默认不可见（P0-2） | 修复 P0-2 后补中心像素断言 |
| D-5 | 设计文档曾按 Intervention v3 编写：L43/L64 写 v3、L80 写 `place()`、L199-244 的语义对齐表、L280 示例缺少必填 `font`（已处理） | 已于 2026-10-20 更新为 `intervention/image` 4.4.x（v4）语义；新增 §7.1.2 迁移差异表；示例补 `font`；移除 `place()` 表述 |
| D-6 | `tests/Image/ImageModuleTest.php` 仅 8 个测试、30 个断言，覆盖 SizeCalculator、FormatNegotiator、配置解析、PNG 编码、路径像素预检、非法二进制；无水印/overlay/contain/encode 合并/bmp-tiff/二进制预检 | 按第 7.2 节清单补齐 |

**基线测试结果**：

```text
./vendor/bin/phpunit -c tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Image
8 tests, 30 assertions, 全部通过（2 个 PHPUnit deprecation）
```

测试通过不代表上述问题不存在：现有断言没有触达水印定位、透明度、`contain` 输出尺寸、encode 合并、bmp/tiff 编码与二进制像素防护。

---

## 7. 修复路线

### 7.1 第一批：P0（已完成，2026-10-20）

1. 新增 `PositionAnchor::insertOffsets()`，与 `resolve()` 分离两套坐标，修正 `watermarkImage()` 定位。
2. 修正 `watermarkImage()` 透明度，直接使用 `opacity`。
3. 重写 `watermarkText()` 颜色与 alpha 生成，改用 `Rgb\Color`。
4. 重写 `overlayCenter()`：`insert($overlay, 0, 0, CENTER, 1.0)`，默认不透明。
5. 新增 `tests/Image/WatermarkPixelTest.php`，覆盖九宫格、透明度、overlay、文字颜色与 `withLogo()` 链路。
6. README 无需改动：实现已与 L253（越大越不透明）、L580（正中叠加）的描述对齐。

### 7.2 第二批：P1 正确性与安全（已完成，2026-10-20）

以下 9 项均已落地，变更文件与验证数据见 §8.6：

1. `EncodeOptions::merge()` 改可变参数，实现三级合并。
2. `applyPresetConfig()` 重置 `presetEncode`。
3. `EncodeOptions` 增加 `maxBytes`，修复 P1-8。
4. bmp/tiff 二选一：移除白名单或补编码器，保证 extension/mime 一致。
5. 二进制 `getimagesizefromstring()` 预检；水印/overlay 统一受检解码。
6. `contain` 改 `scaleDown()`，新增 `pad` 模式（如需）。
7. resize 参数校验与单维等比处理。
8. 目标尺寸 `max_pixels` 校验。
9. 公共 API 异常包装。

### 7.3 第三批：P2 健壮性（已完成，2026-10-20）

按「影响线上排障的优先」落地：P2-1（driver 静默回退）、P2-7/P2-8（路径语义与 LFI 面）、P2-11（能力检测）、P2-12（TOCTOU）、P2-17（内部编码绕过）先行；P2-2/P2-4/P2-5/P2-16 归入编码入口校验，P2-9/P2-10/P2-13/P2-14/P2-15 随模块清理。P2-3 已随 P1-6、P2-6 已随 P1-9 提前关闭。变更文件与实测见 §8.7。

### 7.4 回归测试清单

下列清单已由 `tests/Image/WatermarkPixelTest.php`、`tests/Image/P1RegressionTest.php` 与 `tests/Image/P2RegressionTest.php` 覆盖（2026-10-20）：

| 用例 | 断言 |
|---|---|
| 图片水印 9 个位置 × 2 种尺寸 | logo 像素区域符合预期，边缘距离等于 offset |
| `opacity` 0 / 0.5 / 1.0 | 采样像素 alpha 单调且与配置一致 |
| 文字水印 `#fff` / `#ffffff` / `red` / 8 位 hex | 均可渲染，alpha 符合 opacity |
| `overlayCenter()` | 中心像素为 logo 颜色；默认可见 |
| `contain` 大图/小图/单维 | 输出尺寸符合「不补白、不放大」 |
| encode 三种优先级组合 | format/quality 逐字段正确 |
| preset 连续切换 | 不残留上一个 preset 的 encode |
| bmp/tiff | 不支持则抛库异常；支持则 magic/mime/extension 一致 |
| 高压缩比二进制 | 解码前抛 `ImageTooLargeException` |
| 超限 preset 尺寸 | 分配内存前抛 `ImageTooLargeException` |
| 非法 driver / 运行时能力 | 构造抛 `InvalidConfigException`；`FormatNegotiator` 按 `gd_info()` / `queryFormats()` 过滤 |
| quality / thumbnail 越界 | 入口抛 `InvalidImageException` |
| 透明 PNG 默认编码 | `image/png` 且 alpha 保留；`create()` 仍默认 jpeg |
| 相对水印路径 / 越界路径 | 按 `base_path` 解析；不在 `allowed_roots` 内抛异常 |
| overlay 复用 / 流 rewind / EXIF | 居中包围盒正确、overlay 不被 `cover()` 修改、方向只转一次 |
| GIF strip / `encodePngBinary` | 前者抛 `UnsupportedFormatException`，后者受 `max_bytes` 约束 |

---

## 8. 附录

### 8.1 实测环境

| 项目 | 值 |
|---|---|
| PHP | 8.4.14 |
| GD | 2.x（`imagecreatetruecolor`、`imagecopy`、`imagettftext` 可用） |
| Imagick | 已安装，`queryFormats` 含 AVIF/TIFF/BMP |
| intervention/image | 4.4.x |
| 复现方式 | 临时 PHP 脚本 + `ImageManager` 公共 API，不修改源码 |

### 8.2 关键 vendor 语义依据

| 语义 | vendor 位置 | 结论 |
|---|---|---|
| `insert()` 坐标 | `src/Size.php::movePivot()` L150-196 | `$x/$y` 是相对锚点偏移；右侧/底部为内缩量 |
| `insert()` 透明度 | `src/Drivers/Gd/Modifiers/InsertModifier.php` L57、L89-93；`src/Modifiers/InsertModifier.php` L25-29 | `1.0` 原图，`0.0` 全透明 |
| `contain()` | `src/Modifiers/ContainModifier.php` + GD 实现 | 固定目标画布、居中、背景填充 |
| `resize()` | `src/Modifiers/ResizeModifier.php` | 宽高独立拉伸 |
| 8 位 hex alpha | `src/Colors/Rgb/Decoders/HexColorDecoder.php` L72-76 | alpha 0–255 归一化 |
| 文字 position | `src/Drivers/AbstractFontProcessor.php::buildPivot()` L156-173 | position 是锚点，配合 `font->align()` 使用 |

### 8.3 复现要点

- P0-1：100×100 画布 + 40×40 logo，`bottom-right`、`16`，读出红色像素包围盒。
- P0-2：200×200 画布 + 40×40 overlay，比较中心 `(100,100)` 与右下 `(180,180)` 的像素。
- P0-3/P0-4：同一 logo/文字分别用 `opacity=0.5`、`1.0` 输出，读取 alpha 通道。
- P1-2：全局 jpeg + preset png + `EncodeOptions(quality: 90)`，检查输出 magic 与 `ProcessResult`。
- P1-4：Imagick 下 `format=bmp`、`tiff`，检查 magic、mime、extension。
- P1-6：`resize(20, null, 'scale')`、`resize(0, 0, 'contain')`、`resize(0, 100, 'cover')` 读取 `size()`。
- P1-8：preset `encode.max_bytes=1`，确认是否抛 `ImageTooLargeException`。

### 8.4 P0 复验记录（修复前，2026-10-20）

> 以下为**修复前**数据；修复后复验见 §8.5。

复验方式：仅通过 `ImageManager` 公共 API 生成 PNG 后做像素扫描，不修改源码；素材为 40×40 纯红 logo、白/黑底画布、`src/Captcha/Font/captcha0.ttf`。**结论：P0-1 ~ P0-4 全部存在。**

**P0-1 九宫格定位**（200×120 画布、40×40 logo、offset 16；取 `opacity=0.0` 以获得完全不透明的水印，原因见下方耦合说明）

| position | 期望左上角 | 实测红色包围盒 | 结果 |
|---|---|---|---|
| top-left | x16 y16 | x16y16 ~ x55y55 | 正确 |
| top | x80 y16 | x180y16 ~ x199y55 | 错位（超出右边被裁） |
| top-right | x144 y16 | x0y16 ~ x15y55 | 错位（翻到左侧） |
| left | x16 y40 | x16y100 ~ x55y119 | 错位（翻到底部） |
| center | x80 y40 | x180y100 ~ x199y119 | 错位（跑到右下角） |
| right | x144 y40 | x0y100 ~ x15y119 | 错位（左下角） |
| bottom-left | x16 y64 | x16y0 ~ x55y15 | 错位（翻到顶部） |
| bottom | x80 y64 | x180y0 ~ x199y15 | 错位（右上角） |
| bottom-right | x144 y64 | x0y0 ~ x15y15 | 错位（左上角） |

**P0-2 `overlayCenter()`**（200×200 画布、40×40 overlay，期望 x80y80 ~ x119y119）

| 调用 | 实测结果 |
|---|---|
| `overlayCenter($logo, 40, 40)`（默认 `opacity=0`） | 无任何 overlay；输出二进制与未叠加时**完全相同** |
| `overlayCenter($logo, 40, 40, 1.0)` | x160y160 ~ x199y199（贴右下角，非居中） |

**P0-3 不透明度**（`top-left`，白底 + 纯红 logo，采样 (30,30)；白底上红色不透明度 p 时 green = 255×(1-p)）

| 配置 opacity | 实测 rgb | 折算不透明度 | 期望不透明度 |
|---|---|---|---|
| 1.0 | (255,255,255) | 0.00 | 1.00 |
| 0.6 | (255,152,152) | 0.40 | 0.60 |
| 0.3 | (255,76,76) | 0.70 | 0.30 |

**P0-4 文字水印 alpha**（黑底 + 白字 `ABC`，size 60，取文字区域最大 r 值）

| color / opacity | 实测 | 期望 |
|---|---|---|
| `#ffffff` / 1.0 | r=100（≈ 0.39 不透明） | r≈255 |
| `#ffffff` / 0.5 | r=50（≈ 0.20 不透明） | r≈128 |
| `#fff` / 1.0 | `InvalidArgumentException: Hex color has an invalid format` | 正常渲染 |
| `red` / 1.0 | `ColorDecoderException: Unknown color format` | 正常渲染 |

> 说明：4 项均可用像素断言固定，建议直接转为 §7.4 的回归用例。

#### P0-1 与 P0-3 的耦合关系（实测）

`watermarkImage()` 先计算 `1.0 - $mark->opacity`（L155），再传给 `insert()` 的 `$transparency`。实测 `bottom-right`、offset 16、画布 200×120（期望左上 `(144,64)`；因 P0-1 错位，实际可见区为 `(0,0)`~`(15,15)`）：

| 配置 opacity | 实际传入 transparency | 期望区 (150,70) | 错位区 (5,5) | 表现 |
|---|---|---|---|---|
| 1.0 | 0.0 | rgb(255,255,255) | rgb(255,255,255) | 全透明，输出与无水印一致 |
| 0.7 | 0.3 | rgb(255,255,255) | rgb(255,178,178) | 淡红可见 |
| 0.5 | 0.5 | rgb(255,255,255) | rgb(255,126,126) | 淡红可见 |
| 0.3 | 0.7 | rgb(255,255,255) | rgb(255,76,76) | 明显可见 |
| 0.0 | 1.0 | rgb(255,255,255) | rgb(255,0,0) | 纯红，最清晰 |

由此可以确认四点，其中第 2 点修正了「必须用 `opacity=0.0` 才能观察定位」的粗略说法：

1. **默认调用被完全掩盖，且是「掩盖」而非「抵消」。** `ImageWatermark` 默认 `opacity=1.0`，反转后 `transparency=0.0`，水印 100% 透明；此时无论定位对错，输出都等于无水印，P0-1 在默认路径下**不可能被观察到**。
2. **并非只有 `opacity=0.0` 能看到水印。** 任何 `opacity < 1.0` 都能画出水印（0.3~0.7 为淡红）。但像素断言若用 `green < 120` 这类阈值，`opacity ≥ 0.5` 会被过滤（实测 green=126、178，被判成「无水印」）。因此**复验与回归用例统一取 `opacity=0.0`**（`transparency=1.0`，原图不透明），既最清晰也最不依赖阈值。
3. **建议同批修复，但技术上可分开。** 修掉 P0-3（去掉 `1.0 -`，直接传 `opacity`）后，默认调用立即变为不透明，P0-1 的错位会在第一次默认调用时暴露；若两项分批上线，冒烟测试容易把错位误判为「修透明度引入的新回归」。§7.1 因此把两者放在同一批。
4. **`overlayCenter()` 的成因不同，不能合用一个修复。** 它直接把 `$opacity` 传给 `insert()`（L292，未取反），默认 `0` 在 v4 下即全透明；`LogoApplier` 又未传该参数（L42），所以 `withLogo()` 恒为不可见。P0-2 与 P0-3 是两套独立的 opacity 误用。

### 8.5 P0 修复后复验与测试（2026-10-20）

**变更文件**

| 文件 | 改动 |
|---|---|
| `src/Image/Support/PositionAnchor.php` | 新增 `insertOffsets()`：返回相对锚点偏移，并按 position 清零居中轴的 offset |
| `src/Image/ImageEditor.php` | `watermarkImage()` 改用 `insertOffsets()` 且不再取反 opacity；`overlayCenter()` 改为 `(0, 0, CENTER)` 且默认 `1.0`；新增 `textColor()` 统一颜色与 alpha |
| `src/Image/Watermark/ImageWatermark.php` | 更新 `opacity` 注释：明确直接作为 v4 insert 的不透明度因子（1.0 为原图） |
| `tests/Image/WatermarkPixelTest.php` | 新增 8 个像素级用例 |

**修复后实测（与 §8.4 同口径）**

P0-1（200×120 画布、40×40 logo、offset 16、opacity=1.0）：**9/9 全部命中，错位 0/9**。

| position | 实测包围盒 |
|---|---|
| top-left | x16y16 ~ x55y55 |
| top | x80y16 ~ x119y55 |
| top-right | x144y16 ~ x183y55 |
| left | x16y40 ~ x55y79 |
| center | x80y40 ~ x119y79 |
| right | x144y40 ~ x183y79 |
| bottom-left | x16y64 ~ x55y103 |
| bottom | x80y64 ~ x119y103 |
| bottom-right | x144y64 ~ x183y103 |

P0-2（200×200 画布、40×40 overlay）：

| 调用 | 中心 (100,100) | 四角 | 结论 |
|---|---|---|---|
| 默认（`opacity=1.0`） | rgb(255,0,0) | 全白 | 居中且可见 |
| `opacity=1.0` | rgb(255,0,0) | 全白 | 居中且可见 |
| `opacity=0.5` | rgb(255,126,126) | 全白 | 居中且半透明 |

`QrcodeBuilder::withLogo(..., 0.15)`：logo 红色包围盒 `x127y127 ~ x171y171`，位于码图中心且可见。

P0-3（白底、纯红 logo、采样 (30,30)）：

| 配置 opacity | 实测 rgb | 折算不透明度 |
|---|---|---|
| 1.0 | (255,0,0) | 1.00 |
| 0.6 | (255,102,102) | 0.60 |
| 0.3 | (255,178,178) | 0.30 |
| 0.0 | (255,255,255) | 0.00 |

P0-4（黑底 + 白字 `ABC`，size 60）：

| color / opacity | 实测 | 结论 |
|---|---|---|
| `#ffffff` / 1.0 | 最大 r=255 | 不透明 |
| `#ffffff` / 0.5 | 最大 r=128 | alpha≈128 |
| `#fff` / 1.0 | 最大 r=255 | 缩写可解析 |
| `red` / 1.0 | 最大 r=255，红色像素 1594 | 命名色可解析 |

**测试结果**

```bash
./vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Image tests/Qrcode
# OK — Tests: 23, Assertions: 110
```

- 新增 `WatermarkPixelTest`：8 个用例，覆盖九宫格、opacity、overlay、文字 alpha/颜色、非法颜色异常与 Qrcode 链路。
- 既有 `ImageModuleTest` 与 Qrcode 用例无回归。
- 注：输出的 PHPUnit Deprecations 来自既有测试的 `@group` docblock 元数据，与本次改动无关；对整个 `tests/` 目录跑全量会在加载 `RedisPoolTest` 时因需要协程环境而 fatal，属既有环境限制，故按模块分别执行。

**两个实现要点**

1. **居中轴必须清零 offset**：`insert()` 的 x/y 对 `top`/`bottom` 仍会参与水平 pivot 计算，若直接把 `offsetX` 传入，`top` 会从居中变成右移 16px（首轮测试即因此失败）。`insertOffsets()` 因此按 position 将无定位意义的轴置 0。
2. **8 位 hex 的 AA 是不透明度**：`#RRGGBBAA` 中 `00` 全透明、`ff` 不透明，与 `opacity` 同向。首版误按 `1 - opacity` 换算，导致 `opacity=1.0` 渲染为全透明（测试中文字像素 r=0）；已改为把 `opacity` 直接交给 `Color::create()` 的 alpha 通道。

---

### 8.6 P1 修复记录（2026-10-20）

**变更文件**

| 文件 | 改动 |
|---|---|
| `src/Image/ValueObject/EncodeOptions.php` | 新增 `maxBytes`；`merge()` 改为可变参数逐字段合并 |
| `src/Image/Config/ImagePresetConfig.php` | `encodeOptions()` 透传 `max_bytes` |
| `src/Image/Support/FormatNegotiator.php` | 从 Imagick 白名单移除 bmp/tiff，只保留有 encoder 的格式 |
| `src/Image/Support/PixelGuard.php` | 新增 `assertBinary()`、`assertStream()`、`bufferedStream()` |
| `src/Image/ImageManager.php` | 二进制/流解码前像素预检；`create()` 颜色异常包装 |
| `src/Image/ImageEditor.php` | resize 语义/参数/目标像素校验；preset 重置；三级 encode 合并；水印/overlay 受检解码；统一异常包装 |
| `tests/Image/P1RegressionTest.php` | 新增 20 个用例 |
| `src/Image/README.md` | 同步 mode 语义、像素预检与 `max_bytes` 说明 |

**关键行为（实测通过）**

| 项 | 实测结果 |
|---|---|
| contain 1000×500 + (400,400) | 400×200，无补白 |
| contain 100×50 / 20×10 + (400,400) | 保持原尺寸，不放大 |
| contain(400, null) | 400×200 |
| scale(20, null) | 20×10 |
| `resize(0,0)` / `resize(20,null,'cover')` / 未知 mode | `InvalidImageException` |
| 全局 jpeg + preset png + `EncodeOptions(quality:90)` | `image/png` + PNG magic |
| preset A(webp) → preset B(无 encode) | `image/jpeg` + `jpg` |
| preset `max_bytes=1` | `ImageTooLargeException` |
| imagick 请求 bmp/tiff | `UnsupportedFormatException` |
| 100×100 PNG 二进制 + `max_pixels=50` | 解码前 `ImageTooLargeException` |
| 不可 seek 流（socket pair） | 缓冲后正常解码 40×30 |
| logo 150×150 + `max_pixels=20000` | `ImageTooLargeException` |
| `scale(10000,10000)` + `max_pixels=1M` | 分配前抛 `ImageTooLargeException` |
| `cover(10000,100)` + `max_pixels=1M` | 按中间 10000×10000 拦截 |
| `crop(0,0,2000,2000)` + `max_pixels=1M` | `ImageTooLargeException` |
| jpeg `quality=200` | `InvalidImageException`（`previous` 为 Intervention 异常） |

**测试结果**

```bash
./vendor/bin/phpunit --configuration tests/phpunit.xml tests/Image
# OK — Tests: 36, Assertions: 156

./vendor/bin/phpunit --configuration tests/phpunit.xml tests/Qrcode
# OK — Tests: 7, Assertions: 9
```

**实现要点**

1. **contain 不再调用 v4 `contain()`**：后者固定目标画布并补白；改为 `scaleDown()`，单维/双维统一为「等比、只缩不放、不补白」。
2. **cover 必须按中间尺寸校验**：100×100 → `cover(10000, 100)` 的中间画布是 10000×10000，只校验最终 10000×100 会放过一次 1 亿像素分配。
3. **流预检要求可定位**：可 seek 流读取头部（最多 1MB，兼容大段 EXIF）后复原位置；不可 seek 流先复制到 `php://temp`，再预检、解码。
4. **`guard()` 只包装非模块异常**：`ImageTooLargeException` / `UnsupportedFormatException` 等 `ImageException` 原样透传，避免调用方丢失语义。

---

### 8.7 P2 修复记录（2026-10-20）

**变更文件**

| 文件 | 改动 |
|---|---|
| `src/Image/Config/ImageConfig.php` | `driver()` 非法值抛 `InvalidConfigException`；新增 `basePath()`；水印配置注入 `base_path` 与默认 `allowed_roots` |
| `src/Image/Config/ImageWatermarkConfig.php` | 新增 `allowedRoots` / `basePath`，`withOverrides()` 支持 `allowed_roots` / `base_path` |
| `src/Image/Config/TextWatermarkConfig.php` | 新增 `basePath`，相对字体路径按其解析 |
| `src/Image/Config/ImagePresetsConfig.php` | 数字键 preset 名统一转 string，`names()` 稳定返回 `list<string>` |
| `src/Image/Exception/InvalidConfigException.php` | 新增配置异常，与图片内容异常分离 |
| `src/Image/Support/PathResolver.php` | 新增：`realpath` 解析、拒绝 `..`、根目录白名单 |
| `src/Image/Support/FormatNegotiator.php` | 运行时能力过滤（`gd_info()` / `Imagick::queryFormats()`），非法 driver 抛配置异常 |
| `src/Image/Support/ExtensionGuard.php` | 未知 driver 改为抛 `InvalidConfigException` |
| `src/Image/Support/PixelGuard.php` | `assertStream()` 先 rewind；不可 seek 流统一缓冲到 `php://temp` |
| `src/Image/Support/SizeCalculator.php` | 保留公共类，`ImageEditor::resize()` 的 contain 分支直接复用 |
| `src/Image/ImageManager.php` | 构造期校验 driver；路径/SplFileInfo 改为 `fopen()` 句柄预检 + `decodeStream`；移除重复 `orient()` |
| `src/Image/ImageEditor.php` | quality 0–100 入口校验；`thumbnail()` 至少一维 >0；默认格式按源图 mediaType；GIF strip 显式拒绝；新增 `overlayCenterImage()`；`encodePngBinary()` 统一走 `encode()`；contain 复用 `SizeCalculator` |
| `src/Image/Watermark/ImageWatermark.php` | 路径经 `PathResolver` 解析并按 `allowed_roots` 校验 |
| `src/Image/Watermark/TextWatermark.php` | 字体路径经 `PathResolver` 解析，相对路径基于 `base_path` |
| `tests/Image/P2RegressionTest.php` | 新增 19 个用例 |
| `src/Image/README.md` | 同步 driver 校验、运行时能力、`base_path`/`allowed_roots`、默认格式/quality/strip、解码来源与异常表 |

**关键行为（实测通过）**

| 项 | 实测结果 |
|---|---|
| `driver=imagik` | 构造 `ImageManager` 抛 `InvalidConfigException`，消息含原值 |
| GD 无 avif | `supported('gd')` 不含 avif；`webp` 是否可用与 `gd_info()['WebP Support']` 一致 |
| `quality` 101 / -1 / 200 | 入口抛 `InvalidImageException`，消息含 `quality` |
| `thumbnail(0, 0)` | 抛 `InvalidImageException`；`thumbnail(0, 25)` / `thumbnail(25, -1)` 正常 |
| 透明 PNG 无 format | 输出 `image/png`，alpha=127 保留 |
| `create()` 无 format | 输出 `image/jpeg` |
| 显式 `format=jpeg` + 透明 PNG | 输出 `image/jpeg`（显式优先） |
| `base_path` + 相对 logo | 解析到 base_path 下文件并成功叠加 |
| 绝对 logo 在 `allowed_roots` 外 | 抛 `InvalidImageException` |
| 显式 `allowed_roots` 放行 | 可正常读取 |
| `new ImageWatermark($path)` 不传根目录 | 保持兼容，`allowedRoots === []` |
| `overlayCenterImage(20,20)` | 100×100 画布包围盒 `[40,40,59,59]`，原 overlay 仍为 40×40 |
| `SizeCalculator::contain()` vs `resize(..., 'contain')` | 4 组尺寸完全一致 |
| 数字 preset 名 | `names()` 返回 `['0']` |
| 流 fseek(10) 后 open | 正常解码 40×30 |
| EXIF Orientation=6 | 40×20 输出 20×40，只旋转一次 |
| GIF + strip=true | `UnsupportedFormatException` |
| `encodePngBinary()` + `max_bytes=10` | `ImageTooLargeException` |

**测试结果**

```bash
./vendor/bin/phpunit --configuration tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Image
# OK — Tests: 55, Assertions: 218

./vendor/bin/phpunit --configuration tests/phpunit.xml --bootstrap tests/bootstrap.php tests/Image tests/Qrcode
# OK — Tests: 62, Assertions: 227
```

**实现要点**

1. **driver 校验与扩展检测分离**：构造期只校验取值，`ext-gd` / `ext-imagick` 仍延迟到首次 `open()` / `create()`，保持「未使用图像的应用可正常启动」。
2. **能力检测只做收窄**：GD 静态白名单仍不含 avif；运行时过滤只会减少可选格式，不会引入白名单外格式。
3. **TOCTOU 用文件句柄收敛**：`fopen('rb')` 后对同一句柄做头部预检与 `decodeStream`，`origin()->setFilePath()` 仅用于保留路径信息；解码后再复核宽高。
4. **流先 rewind**：可 seek 流回到起点再预检；不可 seek 流复制到 `php://temp`，避免 `decodeStream()` 读到半截数据。
5. **EXIF 只校正一次**：v4 解码器默认 `autoOrientation=true`；移除 `ImageManager::open()` 中显式 `orient()`，并用 Orientation=6 fixture 固定行为。
6. **透明默认 PNG 仅兜底**：只有三层合并后 `format` 为空时才按源图 `mediaType()` 选择；显式 jpeg 仍尊重调用方。
7. **路径白名单默认安全**：经 `ImageConfig::watermarkImage()` 构建时默认 `allowed_roots=[base_path]`；直接 `new ImageWatermark()` 仍为 `[]`，避免破坏既有调用。
8. **`encodePngBinary()` 统一管线**：改为 `encode(new EncodeOptions(format: 'png'))`，因此同样受 preset 合并、异常包装与 `max_bytes` 约束。

---

**报告结束。** P0、P1、P2 与 D 类共 36 项已全部修复；`tests/Image` 55 个用例、218 个断言，Image + Qrcode 62 个用例、227 个断言全部通过。
