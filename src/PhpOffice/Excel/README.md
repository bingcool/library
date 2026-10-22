# PhpOffice Excel

`PhpOffice` 的 Excel 子模块，提供基于 `xlswriter` 的导出与导入。未来 Word / Pdf 在 `PhpOffice` 下各自新增目录与 README。

命名空间：`Swoolefy\Library\PhpOffice\Excel`  
依赖：`ext-xlswriter`（可选，未安装时不影响本库其它模块）  
PHP：`>= 8.4`

---

## Excel 导出

### 安装扩展

```bash
pecl install xlswriter
# php.ini 中启用 extension=xlswriter.so
```

`composer.json` 通过 `suggest` 提示该扩展，不写入 `require`。未安装扩展时调用导出或导入会抛 `ExcelExtensionMissingException`。

### 最小用法

```php
use Swoolefy\Library\PhpOffice\Excel\AbstractExcelExport;

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
                $order['no'],
                $order['amount'],
                $order['status'],
                $order['created_at'], // DateTimeInterface 自动格式化
            ];
        }
    }

    protected function columnWidths(): array
    {
        return ['A' => 24, 'B' => 14, 'C' => 12, 'D' => 20];
    }
}

$result = (new OrderExport($orders))->export(APP_PATH . '/Storage/exports');

$result->path;      // 绝对路径
$result->fileName;  // 含 .xlsx
$result->rowCount;  // 数据行数，不含表头
$result->size;      // 字节数
```

`export()` 为 `final`，固定执行：校验 → 排他预留文件 → 建表 → 写表头 → 分批写数据 → 收尾钩子 → `output()`。单个实例只允许导出一次。

### 声明式配置

| 方法 | 默认值 | 说明 |
|------|--------|------|
| `fileName(): string` | 短类名 + 时间 + 随机串 | 仅文件名，不接受目录；自动补 `.xlsx`，已存在则失败 |
| `sheetName(): string` | `Sheet1` | 非法字符 / 超长 / 保留名会被拒绝 |
| `columnWidths(): array` | `[]` | `['A' => 24, 'B:C' => 18]`；单列写法内部归一化为 `A:A` |
| `freezeRowCount(): int` | `1` | `0` 关闭冻结 |
| `autoFilter(): bool` | `true` | 是否添加自动筛选 |
| `useConstMemory(): bool` | `true` | xlswriter constant memory 模式 |
| `batchSize(): int` | `1000` | 每批 `data()` 行数，最大 `10000` |
| `dateFormat(): string` | `Y-m-d H:i:s` | `DateTimeInterface` 的字符串格式 |

### 扩展层级

| 层级 | 方式 | 适用需求 |
|------|------|----------|
| L1 声明式 | 覆写上表方法 | 标准二维表格，只调文件名、列宽、冻结、筛选 |
| L2 生命周期钩子 | `configureSheet()` / `configureHeaderFormat()` / `writeHeader()` / `afterRows()` / `autoFilterRange()` / `beforeOutput()` | 多行表头、合并标题、合计行、自定义样式、筛选范围 |
| L3 接管写入 | 覆写 `writeRows()`，必要时同时覆写 `writeHeader()` | 分组报表、逐行合并、特殊单元格 API |

`writeHeader()` 必须返回真实表头行数，基类据此 `setCurrentLine()` 对齐数据起点。`writeRows()` 必须返回真实数据行数；覆写后需自行保证行宽、类型与 Excel 上限，可调用 `normalizeRow()` 复用默认校验。

### 钩子

| 方法 | 时机 | 典型用途 |
|------|------|----------|
| `configureSheet(Excel $excel)` | 建表后、写任何单元格前 | 网格线、标签颜色等非单元格配置 |
| `configureHeaderFormat(Format $format)` | 写表头前 | 字体、底色、边框；先 `parent::` 可保留默认样式 |
| `writeHeader(Excel $excel, array $headers, $format): int` | 写表头 | 多行表头、合并标题、完全自绘表头 |
| `afterRows(Excel $excel, ExcelExportContext $context)` | 数据写完后、筛选前 | 合并单元格、合计行、公式、插图 |
| `autoFilterRange(ExcelExportContext $context): ?string` | 添加筛选前 | 调整范围；返回 `null` 不添加 |
| `beforeOutput(Excel $excel, ExcelExportContext $context)` | 筛选后、`output()` 前 | 最后的单元格级调整 |

`ExcelExportContext` 中 `firstDataRowIndex()` / `nextRowIndex()` 是 0 基（可直接传给 `insertText()`），`nextRowNumber()` 与 `defaultAutoFilterRange()` 使用 1 基（Excel 界面语义）。

### 硬约束

- **文件名安全**：只允许 basename，拒绝路径分隔符、控制字符、`<>:"/\|?*`；目标文件用 `fopen(..., 'x')` 排他预留，绝不覆盖已有文件。
- **`header()` 必须最先写**：xlswriter 要求 `header()` 先于任何单元格写入。`configureSheet()` 不要写单元格；若要在大标题下方写表头，覆写 `writeHeader()` 后不要调用 `header()`，全部改用 `insertText()` / `mergeCells()`。
- **constMemory 下合并已写数据会失败**：`data()` 之后的 `mergeCells()` 触达已放置单元格时抛 `cannot modify the placed cells`。需要合并数据区时 `useConstMemory()` 返回 `false`；表头区域和合计行合并不受此限制。
- **constMemory 下筛选范围结束行必须是最后写入行**：`autoFilter()` 范围若在最后一行之前结束（例如排除 `afterRows()` 追加的合计行），会抛 `cannot modify the placed cells`。需要排除末尾行时关闭 constMemory。
- **单列列宽写法**：xlswriter 2.0.2 的 `setColumn('A', 24)` 不可用，基类会归一化为 `A:A`。
- **单元格类型**：`null` 写空、`bool` 写布尔、`int|float` 写数字、`string` 保持文本（长编号、前导零不会被转数字）、`DateTimeInterface` 按 `dateFormat()`。其它类型抛 `InvalidExcelRowException`。
- **上限**：单 sheet 1,048,576 行（含表头）、16,384 列、单元格文本 32,767 字符；数据行上限 1,048,575。
- 不内置 `autoSize()`；列宽请显式声明，逐列测宽在大数据量下代价高。

### 异常

```text
RuntimeException
└── ExcelException
    ├── ExcelExtensionMissingException
    ├── InvalidExcelExportException
    │   └── InvalidExcelRowException   // rowNumber / columnIndex / columnLetter
    └── ExcelExportException
```

业务 `rows()` 或钩子内抛出的异常原样向上传播；基类仍会关闭句柄并清理预留/半成品文件。

### 测试

```bash
vendor/bin/phpunit tests/PhpOffice/Excel/ExcelExportTest.php
```

覆盖：跨批次生成器、constMemory 开关、中文文件名 / sheet、类型归一化、文本保持、非法表头 / sheet / 文件名 / 目录 / 行、防覆盖、失败清理、实例复用、列宽 / 冻结 / 筛选、多行表头合并、合计行、完全自定义布局、自定义筛选与样式。

---

## Excel 导入

导入面向「Excel → 数据库」：`nextRow()` 流式读取，不调用 `getSheetData()`。`read()` 一次返回全部数据行；`import()` 逐行交给子类的 `handleRow()`，适合大文件落库。

### 总读取函数

`read()` 读取整份文件并返回 `ExcelReadResult`，不需要实现 `handleRow()`：

```php
use Swoolefy\Library\PhpOffice\Excel\AbstractExcelImport;

final class OrderRead extends AbstractExcelImport
{
    protected function expectedHeaders(): ?array
    {
        return ['订单号', '金额', '状态'];
    }
}

$result = (new OrderRead())->read(APP_PATH . '/Storage/imports/orders.xlsx');

$result->headers;         // 归一化后的表头
$result->rows;            // ExcelImportRow[]，全部数据行
$result->rowCount;        // 数据行数
$result->skippedRowCount; // 跳过的空行数
$result->lastRowNumber;   // 最后读取的 Excel 行号
```

`read()` 为 `final`，读取规则与 `import()` 完全一致（sheet、表头、类型、空行、`normalizeCells()`），但不会调用 `handleRow()` / `afterImport()`。全部数据行会驻留内存，大文件请使用 `import()`。

### 模板导入

子类实现 `handleRow()`，逐行处理并落库：

```php
use Swoolefy\Library\PhpOffice\Excel\AbstractExcelImport;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelImportRow;
use Vtiful\Kernel\Excel;

final class OrderImport extends AbstractExcelImport
{
    private array $buffer = [];

    protected function expectedHeaders(): ?array
    {
        return ['订单号', '金额', '状态', '下单时间'];
    }

    protected function cellTypes(): array
    {
        return [
            1 => Excel::TYPE_DOUBLE,
            3 => Excel::TYPE_TIMESTAMP,
        ];
    }

    protected function handleRow(ExcelImportRow $row): void
    {
        $this->buffer[] = [
            'no'     => $row->value('订单号'),   // 表头文本映射
            'amount' => $row->value('金额'),
            'status' => trim((string) $row->value('状态')),
            'time'   => $row->value('下单时间'), // Unix timestamp
        ];

        if (count($this->buffer) >= 500) {
            $this->flush();
        }
    }

    protected function afterImport(int $rowCount): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        // Db::table('orders')->insert($this->buffer);
        $this->buffer = [];
    }
}

$result = (new OrderImport())->import(APP_PATH . '/Storage/imports/orders.xlsx');

$result->rowCount;        // handleRow 处理的数据行数
$result->skippedRowCount; // 跳过的空行数
$result->headers;         // 归一化后的表头
$result->headerRowNumber; // 表头 Excel 行号（0 = 无表头）
$result->lastRowNumber;   // 最后读取的 Excel 行号
```

`import()` 为 `final`，固定执行：校验配置 → 打开工作簿 / sheet → 逐行 `nextRow()` → 表头校验 → 行宽归一化 → `handleRow()` → `afterImport()`。单个实例只能执行一次 `read()` 或 `import()`。

### 声明式配置

| 方法 | 默认值 | 说明 |
|------|--------|------|
| `sheetName(): ?string` | `null` | `null` = 第一个 sheet；指定名称必须存在 |
| `headerRow(): int` | `1` | 1 基；`0` = 无表头 |
| `dataStartRow(): int` | `0` | `0` = 表头下一行（无表头时第 1 行）；显式值必须大于表头行 |
| `expectedHeaders(): ?array` | `null` | 非 null 时与文件表头严格比较，用于模板校验 |
| `useStringType(): bool` | `true` | 全部按字符串读取，保留前导零 / 长编号 |
| `cellTypes(): array` | `[]` | 0 基列 => `Excel::TYPE_*`，按列覆盖读取类型 |
| `skipEmptyRows(): bool` | `true` | 空行不进入结果 / `handleRow()`，计入 `skippedRowCount` |
| `normalizeCells(array $cells, int $rowNumber): array` | 原样返回 | 行级归一化；返回列表宽度必须与表头一致 |
| `handleRow(ExcelImportRow $row): void` | 默认抛 `LogicException` | 仅 `import()` 调用；异常原样传播，`read()` 无需实现 |
| `afterImport(int $rowCount): void` | 空 | 最后一批落库 / 资源收尾 |

### 表头与列宽

- 表头单元格按字符串读取后 `trim()`；空表头、重复表头、超长表头直接拒绝。
- 默认数据行必须与表头等宽：短行补 `''`；非空多余列抛 `InvalidExcelImportRowException`；纯空白多余列允许并截断。
- `headerRow() = 0` 时不建立表头映射，`values` 为空数组，行宽可变。

### 读取类型

xlswriter 默认推断会破坏前导零（`"00123"` → `123`），因此默认 `useStringType() === true`。需要数值或日期时按列覆盖：

```php
protected function cellTypes(): array
{
    return [
        0 => Excel::TYPE_STRING,     // 编号保持字符串
        1 => Excel::TYPE_DOUBLE,     // 金额，读回 float
        3 => Excel::TYPE_TIMESTAMP,  // 真实日期单元格转 Unix 时间戳
    ];
}
```

`TYPE_TIMESTAMP` 要求单元格是真实日期；默认字符串模式下日期会得到 Excel 序列号字符串，不会自动转时间戳。`TYPE_DOUBLE` 对整数单元格也返回 `float`。

### 行对象

`ExcelImportRow`：

- `rowNumber`：Excel 界面行号（1 基），可直接写日志或错误提示。
- `cells`：0 基列表，已补齐列宽。
- `values`：表头文本 => 值；无表头时为 `[]`。
- `cell(int $index, mixed $default = null)` / `value(string $header, mixed $default = null)`：带默认值读取。
- `isEmpty()`：所有单元格为 `null` 或空白字符串。

### 硬约束

- **流式读取**：不要调用 `getSheetData()`；它会物化整张表并耗尽 `nextRow()` 迭代器。
- **全量读取有内存上限**：`read()` 会把全部 `ExcelImportRow` 载入内存，仅适合小文件；大文件用 `import()`。
- **单实例一次性**：`read()` / `import()` 任选其一且只能执行一次，成功或失败后都不能复用。
- **表头必须存在**：指定 `headerRow()` 在文件中读不到时抛 `InvalidExcelImportException`。
- **异常边界**：业务钩子异常原样传播；xlswriter 读写异常包装为 `ExcelImportException` 并保留 `previous`。
- **文件格式**：不强制 `.xlsx` 后缀，按内容识别；只支持 xlsx 系列，CSV 不支持。
- **前置校验**：文件、sheet、表头、行宽逐层校验，非法行不会进入 `handleRow()`。

### 异常

```text
RuntimeException
└── ExcelException
    ├── ExcelExtensionMissingException
    ├── ExcelExportException
    ├── InvalidExcelExportException
    │   └── InvalidExcelRowException
    └── ExcelImportException
        └── InvalidExcelImportException
            └── InvalidExcelImportRowException   // rowNumber / columnIndex / columnLetter
```

### 测试

```bash
vendor/bin/phpunit tests/PhpOffice/Excel/ExcelImportTest.php
```

覆盖：`read()` 全量读取、只读子类、`read()` / `import()` 共享一次性限制、表头映射与前导零、空行跳过 / 保留、无表头、自定义表头 / 数据起始行、`expectedHeaders`、非法 / 缺失表头、sheet 选择、文件校验、读取类型、多余列、`normalizeCells`、行对象、业务异常、实例复用、非法配置、5000 行流式读取。

---

## 目录结构

```text
Excel/
├── AbstractExcelExport.php
├── AbstractExcelImport.php
├── Exception/
├── README.md
├── Support/
└── ValueObject/
```

Word / Pdf 落地时在 `PhpOffice` 下各自新增目录，拥有独立的 `Abstract*`、`Exception`、`Support`、`ValueObject` 与 README；跨格式公共抽象等第二个格式真正实现后再提取。
