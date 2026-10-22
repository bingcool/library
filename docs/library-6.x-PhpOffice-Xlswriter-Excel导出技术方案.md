# Library 6.x PhpOffice Excel 导出（Xlswriter）技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块目录 | `src/PhpOffice/Excel/`（`PhpOffice` 为模块总目录，未来容纳 Word/Pdf） |
| 命名空间 | `Swoolefy\Library\PhpOffice\Excel` |
| 文档状态 | 已实施（2026-10-22） |
| 日期 | 2026-10-22 |
| 定位 | 基于 `ext-xlswriter` 的 **同步 Excel（.xlsx）导出抽象类**：子类只声明表头与数据行，基类负责校验、批次写入、表头样式、落盘与失败清理 |
| 模块演进 | `PhpOffice/Excel`（v1）→ `PhpOffice/Word`、`PhpOffice/Pdf`（后续，各自独立实现与异常体系） |
| 运行时依赖 | `ext-xlswriter`（容器实测 2.0.2） |
| 开发期依赖 | `viest/php-ext-xlswriter-ide-helper`（仅 IDE 补全，不参与运行时） |

> 关联文档：[Excel 导入技术方案](library-6.x-PhpOffice-Xlswriter-Excel导入技术方案.md)、[Qrcode 与 Barcode 技术方案](library-6.x-Qrcode与Barcode技术方案.md)、[Image 图像处理技术方案](library-6.x-Image图像处理技术方案.md)。三者都是「业务数据 → 文件产物」型模块，但本模块只负责 Excel 导出，不负责 HTTP 下载与对象存储。

---

## 2. 背景与职责边界

业务系统大量存在「列表导出 Excel」需求。当前项目已安装 `xlswriter` 扩展和 IDE helper，但缺少统一的导出基类，容易在业务侧重复出现以下问题：

- 直接操作 `Vtiful\Kernel\Excel`，表头样式、列宽、冻结、筛选各行其是；
- 一次性把几十万行读进内存再写，Worker 内存被打爆；
- 文件名由业务拼接，出现覆盖、路径穿越、并发写同一文件；
- 非法 sheet 名/超长单元格触发 C 层警告甚至中断请求；
- 导出失败留下半截文件或临时文件。

### 2.1 职责归属

| 能力 | 归属 |
|---|---|
| 抽象导出流程、表头/行数据契约、批次写入 | **library** `PhpOffice` |
| 数据查询、权限校验、字段脱敏、业务格式化 | 业务子类 |
| HTTP 下载响应、`Content-Disposition` | **框架** Controller |
| 文件上传 OSS/COS、生命周期清理 | **library** `FileStorageSystem` 或业务 |
| 大导出任务调度 | 框架 Task Worker / 业务队列 |
| DI | v1 无需注册组件，直接 `new` 子类 |

### 2.2 非目标（v1 明确不做）

- 本文档只覆盖导出；Excel **导入/读取**见 [Excel 导入技术方案](library-6.x-PhpOffice-Xlswriter-Excel导入技术方案.md)；
- 不内置多 sheet、图表、图片、公式、超链接、批注、合并单元格等复杂布局的**声明式配置**；这类需求通过覆写扩展点实现（§6.5），基类仍负责文件生命周期；
- 不提供样式 DSL（字体、颜色、数字格式、条件格式）；通过 `configureHeaderFormat()`、`writeHeader()`、`afterRows()` 等钩子直接使用 `Format` / `Excel` 原生 API；
- 不做 CSV / XLS / PDF 输出；
- 不在库内自动投递 Task Worker（库不感知框架 Task 配置）；
- 不做 HTTP 流式下载与断点续传；
- 不封装 `autoSize()`（逐列测宽在大数据量下代价高，v1 由子类显式给列宽）。

---

## 3. 设计目标与原则

1. **最小子类契约**：只强制实现 `headers()` 与 `rows()`；其余均有可覆写默认值。
2. **模板方法**：`export()` 为 `final`，固定「校验 → 预留文件 → 建表 → 写表头 → 分批写数据 → 收尾 → 返回结果」流程，避免各业务重复踩坑。
3. **流式优先**：`rows()` 返回 `iterable`，允许 Generator；基类按批调用 `data()`，不一次性物化全部行。
4. **失败可诊断**：所有校验错误携带字段/行列位置；扩展异常包装并保留 `previous`；部分文件必须清理。
5. **不覆盖、不越界**：文件名只允许 basename；目标文件以排他方式预留；目录必须已存在且可写。
6. **可选依赖**：`ext-xlswriter` 不写入 `composer.json` 的 `require`，通过运行期守卫抛出明确异常；未安装扩展的宿主仍可安装本库并使用其他模块。
7. **同步即同步**：库不伪装异步；大导出由调用方决定是否放入 Task Worker。
8. **分层扩展**：简单需求用声明式钩子（表头、列宽、冻结、筛选、样式）；复杂需求覆写模板方法（多行表头、合并单元格、合计行、自定义写行）；基类只固化「文件生命周期」，不固化「表格长什么样」。

---

## 4. 依赖与运行环境

### 4.1 版本与安装

| 项 | 说明 |
|---|---|
| PHP | `>= 8.4`（与 library 6.x 一致，`declare(strict_types=1)`） |
| 扩展 | `ext-xlswriter` 2.0.2（容器实测），底层 libxlsxwriter |
| IDE 补全 | `viest/php-ext-xlswriter-ide-helper:dev-master`（已在 `require-dev`） |
| 测试 | PHPUnit 11；XML 结构断言需要 `ext-zip`（容器已具备） |

扩展安装（宿主/CI 未安装时）：

```bash
pecl install xlswriter
# 然后在 php.ini 中启用 extension=xlswriter.so
```

### 4.2 Composer 策略

**决策：使用 `suggest` + 运行期守卫，不加入硬性 `require`。**

理由：本库当前硬依赖的扩展（`ext-redis`、`ext-curl` 等）是多数模块的基础能力；Excel 导出属于可选模块。若把 `ext-xlswriter` 写入 `require`，所有未安装该扩展的宿主执行 `composer install/update` 都会失败。

```jsonc
{
    "suggest": {
        "ext-xlswriter": "PhpOffice Excel 导出需要 xlswriter 扩展，安装：pecl install xlswriter"
    }
}
```

- `viest/php-ext-xlswriter-ide-helper` 继续保留在 `require-dev`，不得在 `src/` 内引用其命名空间。
- 守卫在 `export()` 入口执行：`extension_loaded('xlswriter')` 且 `class_exists(\Vtiful\Kernel\Excel::class)`，否则抛 `ExcelExtensionMissingException`。

### 4.3 IDE helper 的常量陷阱（实测）

`viest/php-ext-xlswriter-ide-helper` 的 `Vtiful\Kernel\Format` 常量在 stub 中全部写成 `0x00`：

```php
const FORMAT_ALIGN_CENTER = 0x00;   // stub 占位值
const COLOR_WHITE = 0x00;           // stub 占位值
```

但容器内真实扩展返回的是正确值（`FORMAT_ALIGN_CENTER=2`、`COLOR_WHITE=16777215`、`BORDER_THIN=1`）。因此：

- 代码中可以使用 `Format::FORMAT_ALIGN_CENTER` 等常量，**运行时以扩展为准**；
- 代码评审/静态阅读时不要把 IDE 显示的 `0x00` 当成真实值；
- 颜色直接使用 RGB 整型字面量（如 `0x4F81BD`），不依赖 stub 的 `COLOR_*` 值。

### 4.4 容器实测结论（决定实现细节）

| 行为 | 实测结果 | 方案应对 |
|---|---|---|
| `fileName('noext')` | 输出路径没有扩展名，文件仍为 xlsx 内容 | 基类强制补 `.xlsx` |
| 目标文件已存在 | xlswriter **静默覆盖**（原内容被替换） | 基类用 `fopen($path, 'x')` 排他预留，已存在则抛异常 |
| 非法 sheet 名 `bad/name` | libxlsxwriter 打印 warning，脚本随之中断 | 建表前预校验 sheet 名 |
| 数字字符串 `"123"` / `"00123"` | 写入 sharedStrings，保持文本类型 | 长编号不会被转数字，前导零保留 |
| `null` / 空字符串 | 单元格被跳过，`getSheetData()` 回读为 `''` | 行宽仍按表头列数校验，不依赖单元格是否存在 |
| `bool` | 写为布尔单元格；回读 `true→1`、`false→'0'` | 文档说明回读类型差异 |
| `constMemory()` + 多次 `data()` | 正常工作，`output()` 返回完整路径 | 作为默认模式 |
| `close()`（未 `output()`） | 不生成目标文件，可安全用于失败清理 | 失败时尽力 `close()` 后删文件 |
| `header()` 放在任何单元格写入之后 | 抛 `Vtiful\Kernel\Exception`，禁止先写标题再调 `header()` | 默认模板先 `header()`；完全自定义表头时不要调用 `header()` |
| `insertText($row, $col, ...)` | 按绝对 0 基行写入，并把 currentLine 设为该行 | 自定义表头/合计行使用绝对坐标 |
| `setCurrentLine($n)` + `data()` | `data()` 从 0 基第 `n` 行开始写 | 基类在 `writeHeader()` 后调用 `setCurrentLine($headerRowCount)` 对齐数据起点 |
| `mergeCells()` | 按绝对范围合并，不改变 currentLine | 合并表头/数据行可在钩子中安全调用 |
| `setColumn('A', 20)` | 单列写法在 2.0.2 不可用：constMemory 报 `cannot modify the placed cells`，普通模式报 `row or column index out of range` | 基类把单列键归一化为 `A:A` 后再调用 `setColumn()` |
| `constMemory` 下写数据后 `mergeCells()` 覆盖已写单元格 | 抛 `cannot modify the placed cells`；表头后的合并、合计行 `insertText()` 均正常 | 需要合并数据区的导出覆写 `useConstMemory()` 返回 `false` |
| `constMemory` 下 `autoFilter($range)` | 范围结束行必须等于最后写入行；结束行更小（如排除合计行）抛 `cannot modify the placed cells`，普通模式无此限制 | 需排除末尾行时关闭 constMemory；文档与测试覆盖 |
| 字符串落盘位置 | constMemory 模式为 `inlineStr`（`t="inlineStr"`），普通模式为 sharedStrings（`t="s"`） | 测试断言需同时兼容两种存储形式 |

---

## 5. 目录结构

```text
src/PhpOffice/
├── Excel/                               # v1：Excel 导出（本方案）
│   ├── AbstractExcelExport.php          # 模板入口 + 声明式钩子 + 可覆写写入流程
│   ├── Exception/
│   │   ├── ExcelException.php           # Excel 异常基类
│   │   ├── ExcelExtensionMissingException.php
│   │   ├── InvalidExcelExportException.php
│   │   ├── InvalidExcelRowException.php # 携带行号/列号
│   │   └── ExcelExportException.php     # 扩展/落盘运行时异常
│   ├── Support/
│   │   ├── ExcelExportValidator.php     # 表头、sheet、文件名、列宽、批次校验
│   │   └── ColumnLetter.php             # 1 基列序号 ↔ A/AA/XFD
│   └── ValueObject/
│       ├── ExcelExportContext.php       # 钩子上下文（行列位置/表头数/数据行数）
│       └── ExcelExportResult.php        # 只读导出结果
├── Word/                                # 后续：Word 导出/生成（v1 不创建空目录）
├── Pdf/                                 # 后续：PDF 导出/生成（v1 不创建空目录）
└── README.md                            # PhpOffice 模块总览，当前仅 Excel
```

目录边界：

- **Excel 专属代码全部在 `src/PhpOffice/Excel/` 下**，包括异常、Support、ValueObject，避免未来 Word/Pdf 接入时污染 `PhpOffice` 根目录；
- 命名空间与目录一一对应：`Swoolefy\Library\PhpOffice\Excel\...`；
- 未来 `Word`、`Pdf` 各自拥有独立的 `Abstract*`、`Exception`、`Support`、`ValueObject`；跨格式的公共抽象等第二个格式真正落地时再提取，避免过早设计；
- 模块级 `README.md` 放在 `PhpOffice/` 根，按 Excel / Word / Pdf 分节。

不新增 `Config/`、`Manager`、DI 组件：v1 的配置全部由子类钩子表达，避免过度设计。

---

## 6. 核心 API 设计

### 6.1 `AbstractExcelExport`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel;

use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelExportException;
use Swoolefy\Library\PhpOffice\Excel\Support\ExcelExportValidator;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelExportContext;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelExportResult;
use Vtiful\Kernel\Excel;
use Vtiful\Kernel\Format;

abstract class AbstractExcelExport
{
    private const DEFAULT_BATCH_SIZE = 1000;

    private bool $exported = false;

    // ---- 必须实现：表结构 ----

    /** 表头，非空列表；决定每行列数 */
    abstract protected function headers(): array;

    /** 数据行，允许 Generator；默认写入流程要求每行与表头等宽 */
    abstract protected function rows(): iterable;

    // ---- 声明式配置（简单导出） ----

    /** 不含目录的文件名；基类负责补 .xlsx、校验与防覆盖 */
    protected function fileName(): string
    {
        return (new \ReflectionClass($this))->getShortName()
            . '_' . date('YmdHis')
            . '_' . bin2hex(random_bytes(3));
    }

    protected function sheetName(): string
    {
        return 'Sheet1';
    }

    /**
     * Excel 列范围 => 宽度，例如 ['A' => 24, 'B:C' => 18]
     * 单列写法 A 在内部会归一化为 A:A（2.0.2 的 setColumn 不接受单列）。
     *
     * @return array<string, int|float>
     */
    protected function columnWidths(): array
    {
        return [];
    }

    /** 冻结行数；0=不冻结，默认冻结首行 */
    protected function freezeRowCount(): int
    {
        return 1;
    }

    protected function autoFilter(): bool
    {
        return true;
    }

    /** true=constant memory 模式（默认），false=普通模式 */
    protected function useConstMemory(): bool
    {
        return true;
    }

    protected function batchSize(): int
    {
        return self::DEFAULT_BATCH_SIZE;
    }

    /** DateTimeInterface 的字符串格式；使用对象自身时区 */
    protected function dateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    // ---- 表头 / 样式扩展 ----

    /**
     * 建表后、写任何单元格之前调用。
     * 只能做非单元格配置（gridline/tab color/文档属性等）；不要在此写单元格，
     * 否则默认 writeHeader() 里的 header() 会因「必须先于任何写入」而抛异常。
     */
    protected function configureSheet(Excel $excel): void
    {
    }

    /** 覆写可完全替换默认表头样式；需要保留默认样式时先 parent::configureHeaderFormat($format) */
    protected function configureHeaderFormat(Format $format): void
    {
        $format->bold()
            ->background(0x4F81BD)
            ->fontColor(0xFFFFFF)
            ->fontSize(11)
            ->align(Format::FORMAT_ALIGN_CENTER, Format::FORMAT_ALIGN_VERTICAL_CENTER)
            ->border(Format::BORDER_THIN);
    }

    /**
     * 写表头，返回表头占用行数（0=无表头）。
     * 默认写一行；多行表头/合并标题/完全自定义表头在此覆写。
     *
     * @param resource $headerFormat configureHeaderFormat() 生成的格式资源
     */
    protected function writeHeader(Excel $excel, array $headers, $headerFormat): int
    {
        $excel->header($headers, $headerFormat);
        return 1;
    }

    // ---- 数据写入扩展 ----

    /**
     * 写数据行，返回数据行数。
     * 默认实现：逐行 normalizeRow() 校验 + 按 batchSize() 调用 data()。
     * 完全自定义布局时覆写；可调用 parent::writeRows() 复用校验/分批。
     */
    protected function writeRows(Excel $excel, iterable $rows, array $headers): int
    {
        $rowCount = 0;
        $batch = [];
        foreach ($rows as $row) {
            $rowCount++;
            if ($rowCount > ExcelExportValidator::MAX_DATA_ROWS) {
                throw new InvalidExcelExportException('数据行数超过 Excel 单 sheet 上限');
            }
            $batch[] = $this->normalizeRow($row, $rowCount, $headers);
            if (count($batch) >= $this->batchSize()) {
                $excel->data($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $excel->data($batch);
        }
        return $rowCount;
    }

    /** 单元格归一化；自定义 writeRows 可复用（详见 §7.3） */
    protected function normalizeRow(array $row, int $rowNumber, array $headers): array
    {
        // 校验行宽、单元格类型与长度后返回可写入的一维数组
    }

    // ---- 收尾扩展 ----

    /** 数据写完后、自动筛选前；适合 mergeCells / 合计行 / 公式 / 插图 */
    protected function afterRows(Excel $excel, ExcelExportContext $context): void
    {
    }

    /**
     * 返回 null 表示不添加筛选；多行表头/含合计行时覆写。
     * constMemory 模式要求范围结束行等于最后写入行，需排除 afterRows() 追加行时关闭 constMemory。
     */
    protected function autoFilterRange(ExcelExportContext $context): ?string
    {
        return $context->defaultAutoFilterRange();
    }

    /** 自动筛选后、output 前；最后的单元格级调整机会 */
    protected function beforeOutput(Excel $excel, ExcelExportContext $context): void
    {
    }

    final public function export(string $directory): ExcelExportResult
    {
        // 流程见 §7.1
    }
}
```

设计说明：

- **单实例单次导出**：`export()` 入口检查 `$exported`，第二次调用抛 `ExcelExportException`。原因是 `rows()` 常为 Generator，二次迭代会得到空数据；与其静默产出只有表头的文件，不如快速失败。失败后同样不允许复用实例。
- `headers()` / `rows()` / 各钩子均为 `protected`，只暴露 `export()`，避免业务绕过文件生命周期。
- `writeHeader()` 的返回值是**数据起始行**的依据：基类在调用后执行 `$excel->setCurrentLine($headerRowCount)`，再写数据。自定义多行表头必须返回真实行数，否则数据会错位。
- `writeHeader()` 默认调用 `header()`，而 xlswriter 要求 `header()` 先于任何单元格写入。因此 `configureSheet()` 只能做非单元格配置；若需要在表头上方写标题行，必须整体覆写 `writeHeader()` 且**不要**调用 `header()`（全部改用 `insertText()` / `mergeCells()`）。
- 表头样式 v1 默认：加粗、深蓝底 `0x4F81BD`、白字、水平/垂直居中、细边框；通过 `configureHeaderFormat()` 调整，无需改模板流程。
- `freezeRowCount()` 默认冻结 1 行（`freezePanes(1, 0)`）；多行表头可返回 2；0 表示关闭。
- `autoFilter()` 默认开启；默认范围为「最后一个表头行 + 数据行」，不含 `afterRows()` 追加的合计行。合并单元格与筛选通常冲突，此时业务应返回 `false` 或覆写 `autoFilterRange()`。
- constMemory 模式下 `autoFilter()` 要求筛选范围结束行正好是最后写入行；若自定义范围排除了 `afterRows()` 追加的合计行，必须覆写 `useConstMemory()` 返回 `false`（实测普通模式正常）。
- `writeRows()` 默认承担行宽/类型/上限校验；完全覆写后由业务自行保证这些约束，可调用 `normalizeRow()` 复用默认校验。

### 6.2 `ExcelExportContext`

钩子上下文只描述「表格已经写到哪」，不包含可变状态，方便业务计算合并范围、合计行位置和筛选范围。

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\ValueObject;

use Swoolefy\Library\PhpOffice\Excel\Support\ColumnLetter;

final readonly class ExcelExportContext
{
    public function __construct(
        public array $headers,
        /** 表头占用行数（由 writeHeader() 返回） */
        public int $headerRowCount,
        /** 数据行数（由 writeRows() 返回） */
        public int $dataRowCount,
        public int $batchSize,
    ) {
    }

    public function columnCount(): int
    {
        return count($this->headers);
    }

    public function lastColumnLetter(): string
    {
        return ColumnLetter::fromIndex($this->columnCount());
    }

    /** 数据起始行，0 基，供 insertText($row, $col, ...) 使用 */
    public function firstDataRowIndex(): int
    {
        return $this->headerRowCount;
    }

    /** 下一个可写行，0 基，供 insertText($row, $col, ...) 使用 */
    public function nextRowIndex(): int
    {
        return $this->headerRowCount + $this->dataRowCount;
    }

    /** 下一个可写行，1 基，供展示或范围字符串使用 */
    public function nextRowNumber(): int
    {
        return $this->nextRowIndex() + 1;
    }

    /**
     * 默认筛选范围：最后一个表头行 → 最后一个数据行。
     * 不含 afterRows() 追加的合计行；无表头时返回 null。
     */
    public function defaultAutoFilterRange(): ?string
    {
        if ($this->headerRowCount === 0) {
            return null;
        }

        return 'A' . $this->headerRowCount . ':'
            . $this->lastColumnLetter() . ($this->headerRowCount + $this->dataRowCount);
    }
}
```

> 行号语义容易混：`firstDataRowIndex()` / `nextRowIndex()` 是 **0 基**，对应 `insertText($row, ...)`；`nextRowNumber()` 是 **1 基**，对应 Excel 界面；`defaultAutoFilterRange()` 使用 1 基。业务钩子直接用 Context 方法，不要自己加 `+1/-1`。

### 6.3 `ExcelExportResult`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\ValueObject;

final readonly class ExcelExportResult
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        /** 绝对路径（realpath 后） */
        public string $path,
        /** 含 .xlsx 后缀的文件名 */
        public string $fileName,
        public string $sheetName,
        /** 数据行数，不含表头 */
        public int $rowCount,
        /** 文件字节数 */
        public int $size,
    ) {
    }
}
```

结果只描述「已成功落盘的文件」，不包含二进制内容；下载/上传由框架与 `FileStorageSystem` 负责。

### 6.4 异常体系

```text
RuntimeException
└── Swoolefy\Library\PhpOffice\Excel\Exception\ExcelException
    ├── ExcelExtensionMissingException
    ├── InvalidExcelExportException
    │   └── InvalidExcelRowException   // rowNumber / columnIndex / columnLetter
    └── ExcelExportException
```

| 异常 | 触发场景 |
|---|---|
| `ExcelException` | Excel 子模块异常基类；业务可统一捕获 |
| `ExcelExtensionMissingException` | 未加载 `ext-xlswriter` 或 `Vtiful\Kernel\Excel` 不存在 |
| `InvalidExcelExportException` | 目录非法、表头为空/超限、sheet 名非法、文件名校验失败/已存在、列宽非法、批次大小非法、冻结行数非法、`writeHeader()`/`writeRows()` 返回值非法、超过 Excel 行数上限 |
| `InvalidExcelRowException` | 某行不是 list、列数与表头不一致、单元格类型不支持、字符串超过 32767 字符 |
| `ExcelExportException` | 实例二次导出、xlswriter 运行异常、`output()` 未产出文件/文件为空等 |

业务 `rows()` 或生命周期钩子内部抛出的异常**原样向上传播**，不做包装；但基类仍会清理已预留/半成品文件。

> 异常也全部位于 `PhpOffice\Excel\Exception`，未来 `Word`/`Pdf` 建各自的异常基类；如果届时确实需要跨格式统一捕获，再提取模块级基类，v1 不预留空抽象。

### 6.5 子类用法与扩展层级

#### 6.5.1 简单导出：只声明表头与数据

```php
final class OrderExport extends AbstractExcelExport
{
    public function __construct(private readonly iterable $orders)
    {
    }

    protected function headers(): array
    {
        return ['订单号', '金额', '状态', '下单时间'];
    }

    protected function rows(): iterable
    {
        foreach ($this->orders as $order) {
            yield [
                $order['no'],          // 长编号建议用字符串，避免 15 位以上精度丢失
                $order['amount'],
                $order['status'],
                $order['created_at'],  // DateTimeInterface 自动格式化
            ];
        }
    }

    protected function fileName(): string
    {
        return '订单导出_' . date('YmdHis') . '_' . bin2hex(random_bytes(2));
    }

    protected function columnWidths(): array
    {
        return ['A' => 24, 'B' => 14, 'C' => 12, 'D' => 20];
    }
}

$result = (new OrderExport($orders))->export(APP_PATH . '/Storage/exports');

// $result->path      绝对路径
// $result->rowCount  数据行数
// $result->size      字节数
```

#### 6.5.2 三层扩展模型

| 层级 | 方式 | 适用需求 |
|---|---|---|
| L1 声明式 | 覆写 `fileName()` / `sheetName()` / `columnWidths()` / `freezeRowCount()` / `autoFilter()` / `dateFormat()` | 标准二维表格，只调整文件名、列宽、冻结、筛选 |
| L2 模板钩子 | `configureHeaderFormat()` / `configureSheet()` / `writeHeader()` / `afterRows()` / `autoFilterRange()` / `beforeOutput()` | 多行表头、合并单元格、合计行、标题、自定义样式、筛选范围 |
| L3 接管写行 | 覆写 `writeRows()`，必要时同时覆写 `writeHeader()` | 非标准行布局、分组报表、逐行合并、特殊单元格 API |

**选择原则**：

- 能用声明式解决的不写钩子；
- 能覆写单个钩子解决的不整体接管写入；
- 只有默认 `data()` 批次模型无法表达时，才覆写 `writeRows()`；覆写后需自行保证 Excel 行/列/单元格上限，可复用 `normalizeRow()`。

#### 6.5.3 多行表头 + 合并单元格（覆写 `writeHeader`）

```php
final class SalesExport extends AbstractExcelExport
{
    protected function headers(): array
    {
        return ['订单号', '金额', '状态', '下单时间'];
    }

    protected function rows(): iterable
    {
        // ...
    }

    protected function writeHeader(Excel $excel, array $headers, $headerFormat): int
    {
        // 第 1 行：默认表头；header() 必须是第一个单元格写入
        $excel->header($headers, $headerFormat);

        // 第 2 行：合并说明（绝对坐标，A2:D2）
        $note = new Format($excel->getHandle());
        $note->italic()
            ->fontColor(0x666666)
            ->align(Format::FORMAT_ALIGN_CENTER, Format::FORMAT_ALIGN_VERTICAL_CENTER);
        $excel->mergeCells('A2:D2', '金额单位：元；统计截止 2026-10-22', $note->toResource());

        // 表头共 2 行：基类随后 setCurrentLine(2)，数据从 Excel 第 3 行开始
        return 2;
    }

    protected function freezeRowCount(): int
    {
        return 2;
    }

    protected function autoFilter(): bool
    {
        // 第 2 行是整行合并的说明，不能作为筛选表头
        return false;
    }
}
```

要点：

- `writeHeader()` 返回真实表头行数，基类据此对齐数据起点；
- `mergeCells()` 使用绝对范围，不改变 currentLine；
- 含合并说明行时默认筛选范围会覆盖该行，需关闭筛选或覆写 `autoFilterRange()`；
- 若要在表头上方再写大标题，`header()` 不能再使用（实测会抛异常），必须完全自绘表头，见 §6.5.5。

#### 6.5.4 数据行合并 + 合计行（覆写 `afterRows`）

```php
final class GroupedSalesExport extends AbstractExcelExport
{
    protected function headers(): array
    {
        return ['订单号', '金额', '状态', '下单时间'];
    }

    protected function rows(): iterable
    {
        // ...
    }

    protected function afterRows(Excel $excel, ExcelExportContext $context): void
    {
        // 示例：把 A2:A4 合并为一个订单号；实际范围由业务分组结果计算
        $excel->mergeCells('A2:A4', 'ORDER-001');

        // nextRowIndex() 是 0 基，正好可直接传给 insertText
        $totalRow = $context->nextRowIndex();
        $excel->insertText($totalRow, 0, '合计');
        $excel->insertText($totalRow, 1, 12345.67);
    }

    protected function autoFilter(): bool
    {
        // 合并单元格与自动筛选通常不能共存；需要筛选时改为覆写 autoFilterRange()
        return false;
    }

    protected function useConstMemory(): bool
    {
        // constMemory 下已写数据单元格不能再 mergeCells，合并数据区必须用普通模式
        return false;
    }
}
```

- `afterRows()` 在全部数据写完后执行，适合合并、合计、补公式、插图；
- 默认筛选范围不包含 `afterRows()` 追加的合计行；
- 合并数据单元格后若仍要筛选，Excel 行为不可靠，建议关闭筛选或调整 `autoFilterRange()`；
- **合并已写入的数据单元格必须关闭 constMemory**：实测 constMemory 模式下对已 `data()` 的单元格调用 `mergeCells()` 会抛 `cannot modify the placed cells`；合并表头、追加合计行（`insertText` 到新行）不受影响；
- **constMemory 下筛选范围不能截断**：范围结束行必须是最后写入行。合计行追加在末尾、筛选范围又只到数据末行时，同样需要 `useConstMemory()` 返回 `false`（见 §4.4）。

#### 6.5.5 完全自定义布局（覆写 `writeHeader` + `writeRows`）

```php
final class SectionReportExport extends AbstractExcelExport
{
    protected function headers(): array
    {
        return ['项目', '数量', '金额']; // 逻辑列定义，用于上下文列数/列宽
    }

    protected function rows(): iterable
    {
        // ...
    }

    protected function writeHeader(Excel $excel, array $headers, $headerFormat): int
    {
        // 不调用 header()：自绘大标题 + 表头行
        $title = new Format($excel->getHandle());
        $title->bold()->fontSize(16)->align(Format::FORMAT_ALIGN_CENTER);
        $excel->mergeCells('A1:C1', '2026 年销售报表', $title->toResource());

        // 绝对 0 基行号：1 => Excel 第 2 行
        $excel->insertText(1, 0, '项目');
        $excel->insertText(1, 1, '数量');
        $excel->insertText(1, 2, '金额');

        return 2; // 标题 + 表头共 2 行
    }

    protected function writeRows(Excel $excel, iterable $rows, array $headers): int
    {
        // 基类已 setCurrentLine(headerRowCount)，这里取到数据起始 0 基行号
        $startRow = $excel->getCurrentLine();
        $count = 0;

        foreach ($rows as $item) {
            $excel->insertText($startRow + $count, 0, $item['name']);
            $excel->insertText($startRow + $count, 1, $item['qty']);
            $excel->insertText($startRow + $count, 2, $item['amount']);
            $count++;
        }

        return $count; // 必须返回真实数据行数
    }
}
```

- 完全自定义模式仍复用基类的扩展守卫、目录/文件名校验、防覆盖、失败清理和结果封装；
- 覆写 `writeRows()` 后，默认的行宽/类型/上限校验不再生效，业务需自行保证，或先调用 `normalizeRow()`；
- `headers()` 仍是必填，用于逻辑列数、列宽、筛选范围和结果元数据；若不需要表头，可让 `writeHeader()` 返回 0。

#### 6.5.6 钩子调用顺序与硬约束

```text
configureSheet()
  → configureHeaderFormat() + writeHeader()
  → setCurrentLine(headerRowCount)
  → setColumn()/freezePanes()
  → writeRows()
  → afterRows()
  → autoFilter()
  → beforeOutput()
  → output()
```

| 约束 | 说明 |
|---|---|
| `configureSheet()` 不写单元格 | `header()` 必须早于任何单元格写入，否则抛 `Vtiful\Kernel\Exception`；这里只做 gridline/tab color/文档属性 |
| `writeHeader()` 返回真实行数 | 基类用返回值 `setCurrentLine()`；返回 0 表示无表头，数据从第 1 行开始 |
| 多行/标题表头不调用 `header()` | 一旦自己写了标题，全部改用 `insertText()`/`mergeCells()`；默认实现的 `header()` 不能再调用 |
| `writeRows()` 返回真实数据行数 | 结果对象、`afterRows()` 上下文、筛选范围都依赖它；不得返回含表头/合计行的总数 |
| `afterRows()` 用 Context 算坐标 | 0 基用 `nextRowIndex()`/`firstDataRowIndex()`，1 基展示用 `nextRowNumber()`，不要手写偏移 |
| 合并与筛选二选一 | 合并数据区后默认关闭 `autoFilter()`；必须筛选时覆写 `autoFilterRange()` 并自行验证 Excel 行为 |
| 合并数据区关闭 constMemory | constMemory 下已 `data()` 的单元格不能再 `mergeCells()`；`afterRows()` 合并数据时覆写 `useConstMemory()` 返回 `false` |
| 钩子异常原样传播 | 业务异常不包装，但基类会清理预留/半成品文件 |

---

## 7. 导出流程与校验

### 7.1 `export()` 执行流程

1. `$exported` 检查并置位（单实例单次）；
2. `ExcelExtensionMissingException` 守卫；
3. 校验并规范化目标目录（存在、`is_dir`、可写、`realpath`），**不自动创建目录**；
4. 调用一次 `headers()`，校验表头并确定列数；
5. 校验 `sheetName()`、`fileName()`、`columnWidths()`、`batchSize()`；
6. 拼接目标路径，`fopen($path, 'x')` **排他预留**；失败即「已存在/不可写」；
7. `new \Vtiful\Kernel\Excel(['path' => $directory])`；
8. 按 `useConstMemory()` 调用 `constMemory($fileName, $sheetName)` 或 `fileName($fileName, $sheetName)`；
9. 调用 `configureSheet($excel)`（仅非单元格配置）；
10. 创建 `Format`，调用 `configureHeaderFormat($format)`，再调用 `writeHeader($excel, $headers, $format->toResource())`；校验返回的表头行数 `0~100`；
11. `$excel->setCurrentLine($headerRowCount)`，保证自定义表头后数据起点正确；
12. 应用列宽；若 `headerRowCount > 0` 且 `freezeRowCount() > 0`，校验并调用 `freezePanes($freezeRowCount, 0)`；
13. 调用 `writeRows($excel, $this->rows(), $headers)`，得到数据行数；校验 `0~1,048,575`；
14. 构造 `ExcelExportContext`（headers、表头行数、数据行数、批次大小）；
15. 调用 `afterRows($excel, $context)`；
16. 若 `autoFilter()` 为 true，调用 `autoFilterRange($context)`；非 null 时执行 `autoFilter($range)`；
17. 调用 `beforeOutput($excel, $context)`；
18. `output()` 取路径，校验 `is_file()` 且 `filesize() > 0`；
19. 返回 `ExcelExportResult`。

成功路径不主动 `close()`（`output()` 已完成收尾）。

失败清理（`catch` 中）：

- 若 `Excel` 已创建且尚未 `output()`，尽力调用 `close()`；
- 删除第 6 步预留、被写入一半的目标文件；
- 包装/透传异常（见 §7.5）。

### 7.2 校验规则

| 项 | 规则 | 异常 |
|---|---|---|
| 目录 | 非空、已存在、`is_dir`、`is_writable`；`realpath` 规范化 | `InvalidExcelExportException` |
| 表头 | 非空 list；1~16,384 列；元素为 `string|int|float` 且转字符串后 trim 非空；单值 ≤32,767 字符 | `InvalidExcelExportException` |
| sheet 名 | `mb_strlen` 1~31；禁止 `[ ] : * ? / \`；首尾不能是 `'` | `InvalidExcelExportException` |
| 文件名 | 仅 basename；禁止 `<>:"/\|?*` 与控制字符；禁止 `.`/`..`；trim 后 ≤120 字符；无 `.xlsx` 后缀则自动追加 | `InvalidExcelExportException` |
| 文件已存在 | 排他预留失败；**绝不覆盖** | `InvalidExcelExportException` |
| 列宽 | key 匹配 `^[A-Z]{1,3}(:[A-Z]{1,3})?$` 且起止有序；宽度 0.1~255；单列写法内部归一化为 `A:A` | `InvalidExcelExportException` |
| 批次 | 1~10,000 | `InvalidExcelExportException` |
| 冻结 | `freezeRowCount()` 为 0~实际表头行数；表头为 0 时忽略冻结 | `InvalidExcelExportException` |
| `writeHeader()` 返回 | 0~100 的整数；与真实写入行数一致（业务责任） | `InvalidExcelExportException` |
| `writeRows()` 返回 | 0~1,048,575 的整数；不得包含表头/合计行 | `InvalidExcelExportException` |
| `autoFilterRange()` 返回 | `null` 或非空范围字符串；默认范围见 `ExcelExportContext` | `InvalidExcelExportException` |
| 行 | 必须是 list；列数等于表头列数；索引从 1 开始计 | `InvalidExcelRowException` |
| 单元格 | `null`、`bool`、`int`、`float`、`string`、`DateTimeInterface`、`Stringable`；其它类型拒绝 | `InvalidExcelRowException` |
| 单元格长度 | 字符串 `mb_strlen` ≤32,767 | `InvalidExcelRowException` |
| 总行数 | 数据行 ≤1,048,575（Excel 单 sheet 上限 1,048,576 行含表头） | `InvalidExcelExportException` |

说明：

- sheet 名非法在实测中会让 libxlsxwriter 打印 warning 并中断脚本，**必须前置校验**，不能依赖扩展报错。
- 文件名只取 `basename` 语义，但直接拒绝含 `/`、`\` 的输入，不静默截断，避免业务误以为写到了别的路径。
- 目标文件存在时抛异常而非自动改名：自动改名会让调用方无法预期 `$result->path`；默认文件名已带时间戳+随机串，正常不会冲突。
- 行级校验只在默认 `writeRows()` / `normalizeRow()` 中生效；完全覆写 `writeRows()` 后，业务需自行校验行宽、类型、单元格长度与行数上限。

### 7.3 单元格归一化

| 输入类型 | 写入方式 | 说明 |
|---|---|---|
| `null` | 空字符串 | 回读为 `''`，行宽保持一致 |
| `bool` | 原生布尔 | 回读 `true→1`、`false→'0'`（xlswriter 行为） |
| `int` / `float` | 数值 | 超过 15 位有效数字的编号请业务转字符串 |
| `string` | 文本 | 走 sharedStrings；`=SUM(...)`、`+cmd` 等保持文本，不会变公式；前导零保留 |
| `DateTimeInterface` | `$value->format($this->dateFormat())` | 使用对象自身时区，不强制进程时区 |
| `Stringable` | `(string) $value` | 业务值对象可自行实现 |
| `array` / 其它对象 / resource | 拒绝 | `InvalidExcelRowException`，消息带行号、列号 |

### 7.4 落盘与并发安全

- 默认文件名：`短类名_YmdHis_6位随机hex.xlsx`；
- 自定义文件名：业务应自带唯一性（时间戳+随机串/订单号）；相同名称并发导出时，第二个请求在 `fopen('x')` 处失败，不会互相覆盖；
- 预留文件为 0 字节，libxlsxwriter 会以 `wb` 方式覆盖写入（实测可行）；失败时删除；
- 目录由调用方给定，基类不拼接用户输入的子目录，避免路径穿越；
- 每次导出独立 `Excel` 实例、独立目标文件，无静态共享状态，可在协程中并发调用（但 xlswriter 本身同步阻塞，见 §8）。

### 7.5 异常包装与业务异常透传

```php
try {
    // 预留文件、建表、写入、钩子、output
} catch (\Vtiful\Kernel\Exception $e) {
    $this->cleanup($excel, $path);
    throw new ExcelExportException('Excel 写入失败：' . $e->getMessage(), 0, $e);
} catch (\Throwable $e) {
    $this->cleanup($excel, $path);
    throw $e; // rows()/钩子内的业务异常保持原类型
}
```

只包装扩展异常，不把业务异常、`TypeError` 等编程错误转成 `ExcelExportException`，便于上层按原类型处理。`configureSheet()`、`writeHeader()`、`writeRows()`、`afterRows()`、`beforeOutput()` 内抛出的业务异常同样原样传播，但文件清理逻辑一定会执行。

---

## 8. 性能、内存与协程注意事项

1. **xlswriter 是同步 C 扩展**：导出期间会阻塞当前协程所在的 Worker。Web 请求适合小数据量（建议 <1 万行）；大批量导出必须投递 Task Worker/自定义进程，库不自动调度。
2. **constant memory 默认开启**：`constMemory()` 将中间数据写入临时文件，显著降低峰值内存；与多次 `data()` 批次写入兼容（容器实测）。
3. **批次默认 1000 行**：在内存与调用次数之间折中；可覆写为 1000~5000。批次越大，单次 `data()` 内存占用越高。
4. **不要物化全量数据**：业务 `rows()` 应用 Generator/游标逐行产出；若业务先 `fetchAll()` 再 `yield`，基类的流式优势会被抵消。
5. **单实例单次**：导出实例不跨协程共享；每次请求/任务新建子类实例。
6. **Excel 硬限制**：单 sheet 最多 1,048,576 行 × 16,384 列，单元格文本最多 32,767 字符，sheet 名最多 31 字符；超限在基类前置失败。
7. **临时文件**：`constMemory` 的系统临时文件由扩展在 `output()`/`close()` 时清理；基类失败路径会调用 `close()`，避免残留。
8. **自定义钩子的成本**：`afterRows()` 中的 `mergeCells()`、`insertText()` 是逐次 C 调用，范围很大时会拖慢导出；批量合并应在业务侧先聚合区间。
9. **覆写 `writeRows()` 后**：默认的批次/流式/校验全部失效，业务若自行物化全部行，内存与限流风险由业务承担；能复用 `parent::writeRows()` 就不要完全重写。

---

## 9. 测试方案

### 9.1 单元测试（`tests/PhpOffice/Excel/ExcelExportTest.php`，命名空间 `Swoolefy\Library\Tests\PhpOffice\Excel`）

扩展缺失时 `markTestSkipped('xlswriter required')`；容器与 CI 必须实际执行而非跳过。测试文件统一写入 `sys_get_temp_dir()` 下的唯一目录，`tearDown()` 递归清理。

| # | 用例 | 断言 |
|---|---|---|
| 1 | 基本导出（3 行） | 文件存在、`rowCount=3`、`size>0`；回读表头与数据正确 |
| 2 | 生成器跨批次（2,501 行，`batchSize=1000`） | 行数完整、首批/边界/末行正确 |
| 3 | `constMemory` 开/关 | 两种模式均可回读，数据一致 |
| 4 | 中文文件名 + 中文 sheet 名 | 路径/结果字段正确，可回读 |
| 5 | 类型归一化（null/bool/int/float/string/DateTime/Stringable） | XML 中文本走 `t="s"`、数值无 `t`、布尔 `t="b"`；DateTime 格式正确 |
| 6 | 数字字符串与前导零 | sharedStrings 保留 `"00123"`；公式样式字符串仍为文本 |
| 7 | 表头为空/列数超限 | `InvalidExcelExportException` |
| 8 | sheet 名非法（`bad/name`、32 字符、首尾 `'`） | `InvalidExcelExportException`，不调用扩展 |
| 9 | 文件名非法（`../a`、`a/b`、控制字符） | `InvalidExcelExportException`，目录外无文件 |
| 10 | 目录不存在/不可写 | `InvalidExcelExportException` |
| 11 | 目标文件已存在 | `InvalidExcelExportException`，原文件字节不变 |
| 12 | 行宽不匹配/不支持类型/单元格超长 | `InvalidExcelRowException`，行号列号正确 |
| 13 | `rows()` 抛业务异常 | 原异常类型透传；目标文件与预留文件均不存在 |
| 14 | 实例二次 `export()` | `ExcelExportException` |
| 15 | `ColumnLetter` | 1→A、26→Z、27→AA、52→AZ、53→BA、16384→XFD |
| 16 | 列宽/冻结/筛选 | 解压 `sheet1.xml` 断言 `<cols>`、`<pane>`、`<autoFilter>`（需 `ext-zip`） |
| 17 | 自定义多行表头 + 合并说明行 | `writeHeader()` 返回 2；数据从 Excel 第 3 行开始；`<mergeCells>` 含 `A2:D2`；`<pane ySplit="2">` |
| 18 | `afterRows()` 合并数据 + 合计行（`useConstMemory()=false`） | 合并范围正确；合计行位于 `context->nextRowIndex()`；`rowCount` 不含合计行；关闭筛选后 XML 无 `<autoFilter>` |
| 18.1 | 合计行 + 筛选范围 | 普通模式可排除合计行（`A1:B4`）；constMemory 范围覆盖最后一行（`A1:B3`）时正常 |
| 19 | 完全自定义 `writeRows()` | 自绘标题/表头 + `insertText` 写数据；回读位置正确；返回真实数据行数 |
| 20 | `autoFilterRange()` 覆写 | 返回自定义范围时 XML `<autoFilter ref="...">` 一致；返回 `null` 时不生成筛选 |
| 21 | `configureHeaderFormat()` 覆写 | 自定义字号/颜色生效（XML 样式断言），其余流程不受影响 |
| 22 | 钩子返回值非法 | `writeHeader()` 返回 -1/101、`writeRows()` 返回 -1/超上限、`freezeRowCount()` 超过表头行数 → `InvalidExcelExportException` |

**回读陷阱**：`getSheetData()` 会把数字字符串 `"123"` 回读为 `int 123`、`"12.50"` 回读为 `float 12.5`。因此「字符串保持文本」的断言必须检查 xlsx 内部 XML，不能只看回读值。字符串存储位置随模式变化：constMemory 为 `t="inlineStr"`，普通模式为 `t="s"`（sharedStrings），断言需兼容两者。

### 9.2 swoolefy Test 端到端

在 `swoolefy/Test` 增加 `ExcelController` 与 `/api/excel/verify`（与 Lock/CurlProxy 验证入口同风格，返回 `{"pass":bool,"cases":[...]}`）：

1. 10,000 行导出：文件可回读，表头/首行/末行正确，`rowCount=10000`；
2. 2,500 行 + `batchSize=500`：验证跨批次；
3. 中文文件名/中文 sheet 名；
4. `rows()` 中途抛异常：返回业务错误，`Storage/exports` 无半成品文件；
5. 同名文件二次导出：第二次失败，第一次文件完好；
6. 同一请求内两个协程并发导出不同文件：两份文件均完整、无交叉写入；
7. 多行表头 + 合并单元格：数据起始行正确，合并范围可回读；
8. `afterRows()` 合计行 + 自定义 `autoFilterRange()`：范围与合计行位置正确。

验收时同时检查 Test 服务日志无 fatal、无 libxlsxwriter warning、无临时文件残留。

**实测结果（2026-10-22，容器 `swoolefy-php85-v62`，PHP 8.5.8 / Swoole 6.2.2 / xlswriter 2.0.2）**

`GET /api/excel/verify` 返回 `pass: true`、`cleanup: ok`，8/8 场景通过：

| 场景 | 耗时 | 结果 |
|---|---|---|
| 10,000 行导出 | 121.2 ms | 169,503 字节，回读 10,001 行 |
| 2,500 行 / batch 500 | 20.9 ms | 跨批次正确 |
| 中文文件名 / sheet | 5.2 ms | 订单导出_中文.xlsx / 订单明细 |
| 业务异常清理 | 3.8 ms | 残留 0 |
| 同名防覆盖 | 5.4 ms | 首次文件哈希不变 |
| 双协程并发导出 | 25.5 ms | 2 份文件，各 500 行 |
| 多行表头合并 | 4.9 ms | 表头 2 行，数据从第 3 行开始 |
| 合计行 + 自定义筛选（普通模式） | 4.6 ms | `A1:B4`，合计行第 5 行 |

请求日志无 fatal / libxlsxwriter warning；`Test/Storage/exports` 无残留目录。

### 9.3 验收标准

- [x] 容器内 `vendor/bin/phpunit tests/PhpOffice/Excel/ExcelExportTest.php` 全绿且**未跳过**：45 tests / 209 assertions（23 组，数据提供器展开）；
- [x] 上述 E2E 输出 `pass: true`，8/8 场景通过；导出文件逐 entry 解压校验完整；
- [x] 失败场景无目标文件/预留文件/临时文件残留（`cleanup: ok`，`Storage/exports` 为空）；
- [x] `tests/CurlProxy`、`tests/PubSub`、`tests/Lock` 复跑无失败（CurlProxy/Lock 存在环境相关跳过）；
- [x] `composer validate` 通过（仅保留原有 `psr/clock`、`oss-v2` 未绑定版本约束警告）；`ext-xlswriter` 只进 `suggest`；
- [x] `src/PhpOffice/Excel` 未引用 IDE helper 命名空间，未硬编码 stub 常量值；命名空间与目录结构一致。

---

## 10. 实施步骤

| 阶段 | 内容 | 产出 |
|---|---|---|
| P1 | 异常体系、`ExcelExportResult`、`ExcelExportContext`、`ExcelExportValidator`、`ColumnLetter` | 可独立单测的支撑层 |
| P2 | `AbstractExcelExport` 模板流程、声明式钩子、`writeHeader`/`writeRows`/`afterRows` 扩展点、表头样式、失败清理 | 核心能力 |
| P3 | `tests/PhpOffice` 23 组单测（含多行表头、合并、合计行、完全自定义写入）+ 容器内跑通 | 回归基线 |
| P4 | swoolefy Test E2E（8 场景）、`src/PhpOffice/Excel/README.md`、根 README 入口、`composer.json` 增加 `suggest` | 可交付文档与验证 |

---

## 11. 风险与对策

| ID | 风险 | 影响 | 对策 |
|---|---|---|---|
| R1 | 大导出阻塞 Worker | 请求延迟、协程调度停顿 | 文档明确同步阻塞；大数据量投递 Task Worker；批次写入 |
| R2 | 数据量大导致内存峰值 | Worker OOM | 默认 `constMemory` + Generator + 批次；禁止业务全量 `fetchAll` |
| R3 | 同名文件覆盖/并发写 | 数据丢失、文件损坏 | `fopen('x')` 排他预留；默认名带随机串；已存在即失败 |
| R4 | 文件名路径穿越 | 写到目录外 | 仅接受 basename，拒绝分隔符与 `..`；目录由调用方固定 |
| R5 | 非法 sheet 名触发 C 层 warning/中断 | 请求异常、日志污染 | 前置校验字符与长度 |
| R6 | IDE helper 常量全为 `0x00` | 误判样式值 | 以运行时常量为准；颜色用整型字面量；容器测试覆盖 |
| R7 | 扩展未安装 | 宿主安装失败或运行时报错 | `suggest` 而非硬依赖；`ExcelExtensionMissingException` 明确提示 |
| R8 | 长数字编号精度丢失 | 订单号/身份证号错误 | 文档要求超 15 位有效数字转字符串；字符串保持文本类型 |
| R9 | `rows()` 中途异常留下半成品 | 存储垃圾、误下载 | `catch` 中 `close()` + 删除预留/目标文件；业务异常原样透传 |
| R10 | Excel 行/列/单元格上限 | 文件损坏或写入失败 | 表头 16,384、行 1,048,575、单元格 32,767 前置校验 |
| R11 | `autoSize()` 性能问题 | 大数据量导出变慢 | v1 不启用，列宽由子类显式声明 |
| R12 | 覆写 `writeRows()` 绕过默认校验 | 行宽错误、超限、文件损坏 | 文档明确责任；提供 `normalizeRow()` 复用；测试覆盖非法返回 |
| R13 | 自定义表头行数返回值与实际不一致 | 数据整体错位 | 基类以返回值 `setCurrentLine()`；多行表头测试断言数据起始行 |
| R14 | 先写标题再调 `header()` | 扩展抛异常、请求中断 | `configureSheet()` 禁止写单元格；完整自定义表头时禁用 `header()` |
| R15 | 合并单元格与 autoFilter 冲突 | Excel 打开异常/筛选失效 | 合并数据区时关闭筛选或覆写 `autoFilterRange()`；测试覆盖 |
| R16 | 钩子逐单元格调用过多 | 大范围合并/合计时性能下降 | 业务先聚合区间；仅对必要范围调用 `mergeCells()`/`insertText()` |
| R17 | constMemory 下合并已写数据单元格 | 抛 `cannot modify the placed cells`，导出失败 | 合并数据区的导出覆写 `useConstMemory()` 返回 `false`；文档与测试覆盖 |
| R18 | 单列 `setColumn('A')` 在 2.0.2 不可用 | 普通/constMemory 模式均抛扩展异常 | 校验层统一归一化为 `A:A` 后调用 |
| R19 | constMemory 下 autoFilter 范围结束行早于最后写入行 | 抛 `cannot modify the placed cells`，导出失败 | 需排除合计行时 `useConstMemory()` 返回 `false`；测试覆盖两种模式 |

---

## 12. v2 候选（当前明确不做）

- 多 sheet 与 sheet 级配置；
- 样式 DSL、数字/日期/货币格式、条件格式；
- 图片、图表、公式、超链接、批注、合并单元格的**开箱封装**（v1 已可通过 §6.5 钩子直接调用扩展能力）；
- CSV/XLS 输出与 PDF 导出；
- 异步导出任务、进度查询、下载令牌；
- 自动列宽（按内容采样计算）。

---

## 13. 实施记录（2026-10-22）

| 交付物 | 路径 |
|---|---|
| 模板与扩展点 | `src/PhpOffice/Excel/AbstractExcelExport.php` |
| 异常体系 | `src/PhpOffice/Excel/Exception/`（导出相关 5 个；导入异常见导入方案） |
| 校验与列号工具 | `src/PhpOffice/Excel/Support/`（`ExcelExportValidator`、`ExcelExtension`、`ColumnLetter`） |
| 上下文与结果 | `src/PhpOffice/Excel/ValueObject/`（`ExcelExportContext`、`ExcelExportResult`） |
| 模块文档 | `src/PhpOffice/Excel/README.md`（根 README 已加入口） |
| 单元测试 | `tests/PhpOffice/Excel/ExcelExportTest.php`（45 tests / 209 assertions） |
| Swoolefy E2E | `swoolefy/Test/Controller/ExcelController.php`、`Test/Common/ExcelVerify.php`、`Test/Router/Common/Excel.php`，入口 `GET /api/excel/verify` |

实施中发现并固化到校验层的扩展约束：

1. `setColumn('A', ...)` 单列写法在 2.0.2 不可用，`normalizeColumnWidths()` 统一转 `A:A`；
2. constMemory 下合并已写数据单元格失败，合并数据区需普通模式；
3. constMemory 下 autoFilter 结束行必须等于最后写入行，排除合计行需普通模式；
4. `header()` 必须先于任何单元格写入，完全自定义表头时禁用 `header()`。

---

## 14. 参考

- [Xlswriter 中文文档 - 快速开始](https://xlswriter-docs.viest.me/zhong-wen-jian-ti/quick-start)
- [Excel 导入技术方案](library-6.x-PhpOffice-Xlswriter-Excel导入技术方案.md)
- [viest/php-ext-xlswriter](https://github.com/viest/php-ext-xlswriter)
- [viest/php-ext-xlswriter-ide-helper](https://github.com/viest/php-ext-xlswriter-ide-helper)
- [libxlsxwriter 文档](https://libxlsxwriter.github.io/)
