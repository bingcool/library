# Library 6.x PhpOffice Excel 导入（Xlswriter）技术方案

## 1. 文档信息

| 项目 | 内容 |
|---|---|
| 项目 | `bingcool/library` |
| 分支 | `library-6.x` |
| 模块目录 | `src/PhpOffice/Excel/`（`PhpOffice` 为模块总目录，未来容纳 Word/Pdf） |
| 命名空间 | `Swoolefy\Library\PhpOffice\Excel` |
| 文档状态 | 已实施（2026-10-22） |
| 日期 | 2026-10-22 |
| 定位 | 基于 `ext-xlswriter` 的**流式 Excel（.xlsx）读取/导入抽象类**：`read()` 一次返回全部数据行；`import()` 逐行交给 `handleRow()` 落库。基类负责打开文件、表头映射、类型读取、行宽归一化与游标关闭 |
| 运行时依赖 | `ext-xlswriter`（容器实测 2.0.2） |
| 开发期依赖 | `viest/php-ext-xlswriter-ide-helper`（仅 IDE 补全，不参与运行时） |

> 关联文档：[Excel 导出技术方案](library-6.x-PhpOffice-Xlswriter-Excel导出技术方案.md)。导出与导入共用 `ExcelExtension` 扩展守卫、`ExcelException` 根异常与 `ColumnLetter` 列号工具，但文件生命周期与业务契约独立。

---

## 2. 背景与职责边界

业务侧常有「Excel 模板填报后导入数据库」需求。直接使用 `Vtiful\Kernel\Excel` 容易踩到以下问题：

- 用 `getSheetData()` 一次性把整张 sheet 读进内存，大文件打爆 Worker；
- 默认类型推断把 `"00123"` 读成 `123`，订单号、手机号、身份证号丢失前导零；
- 表头行、数据起始行、标题行混在一起，行号与 Excel 界面对不上，出错无法定位；
- 空行、尾部空列、多余列处理不一致，导致插入脏数据；
- 表头拼写变化后仍静默导入，模板升级时数据错列；
- 异常后 xlswriter 句柄未关闭。

### 2.1 职责归属

| 能力 | 归属 |
|---|---|
| 打开工作簿 / 选择 sheet、逐行读取、表头映射、类型与行宽校验 | **library** `PhpOffice` |
| 整表读取为行对象列表 | `read()`，无需业务实现 |
| 字段业务校验、数据清洗、批量落库、事务 | 业务子类 `handleRow()` / `afterImport()` |
| 文件上传接收、临时目录与清理 | 框架 Controller / `FileStorageSystem` |
| 异步导入任务调度 | 框架 Task Worker / 业务队列 |
| DI | 无需注册组件，直接 `new` 子类 |

### 2.2 非目标（v1 明确不做）

- 不内置 ORM / SQL 生成，不感知具体表结构；
- 不做 CSV、XLS（BIFF）导入，只支持 xlsx 系列；
- 不提供公式计算、图片、批注、合并单元格语义解析；
- 不做多 sheet 合并汇总；一次导入固定一个 sheet，需要多 sheet 时每个 sheet 建一个导入实例；
- 不做异步任务、进度条与断点续传；
- 不自动识别「表头在第几行」；标题行、说明行通过 `headerRow()` / `dataStartRow()` 显式声明。

---

## 3. 读取 API 实测结论

以下行为在容器 `swoolefy-php85-v62`（PHP 8.5.8 / xlswriter 2.0.2）中实测确认，直接决定实现方式：

| 行为 | 实测结论 | 设计影响 |
|---|---|---|
| `getSheetData()` | 一次返回全表，且耗尽内部游标 | 导入禁用；只用 `nextRow()` |
| `nextRow()` | 逐行返回 `array`；EOF 返回 `null`；无效 sheet 返回 `false` | 循环以 `!== null` 判断，`false` 转 `ExcelImportException` |
| 默认类型推断 | `"00123"` → `123`；`true` → `1`；`false` → `"0"` | 默认 `setGlobalType(TYPE_STRING)` |
| `setGlobalType(TYPE_STRING)` | 前导零、数字、日期序列号都按原始文本返回 | 默认策略，保证编号类字段不失真 |
| `setType([列 => TYPE_*])` | 按 0 基列覆盖全局类型，调用顺序无关 | 提供 `cellTypes()` 声明式覆盖 |
| `TYPE_TIMESTAMP` | 真实日期单元格 → Unix 时间戳；整数单元格按日期序列号换算 | 日期列显式声明；默认字符串模式下只得到序列号字符串 |
| `TYPE_DOUBLE` | 整数单元格也返回 `float`（`3` → `3.0`） | 业务断言与落库按浮点处理 |
| 空行 | 中间空行返回 `[]`，行号仍连续；首行空白也返回 `[]` | 不能靠数组下标推 Excel 行号，必须手动计数 |
| 尾部空单元格 | `nextRow()` 会裁剪；空白字符串保留 | 行宽归一化统一补齐 `''`，多余空白列允许 |
| 无效 sheet | `openSheet('不存在')` 不抛异常，后续读取返回 `false` | 先用 `sheetList()` 校验名称 |
| 文件类型 | `.dat` 内含 xlsx 内容可正常打开；CSV 失败 | 不强制后缀，按内容识别，失败后包装异常 |
| 句柄 | 未 `close()` 会占用文件描述符 | `finally` 中关闭，关闭异常不覆盖原异常 |

---

## 4. 设计目标与原则

1. **最小子类契约**：读取整表用 `read()`，无需实现任何业务方法；落库用 `import()`，只需实现 `handleRow(ExcelImportRow $row)`。
2. **底层流式**：始终通过 `nextRow()` 逐行读取；`import()` 内存只与单行宽度和业务缓冲有关，`read()` 仅在小文件场景收集全部行。
3. **行号可信**：手动维护 1 基 Excel 行号，错误和日志直接对应 Excel 界面。
4. **默认无损**：默认全部按字符串读取，优先保证编号、手机号、前导零；数值/日期按列显式开启。
5. **模板可校验**：`expectedHeaders()` 严格比较表头，模板变更时 fail-fast。
6. **非法行不进入业务**：文件、sheet、表头、行宽、列数在 `handleRow()` 前完成校验。
7. **失败可诊断**：异常携带行号/列号/列字母；xlswriter 异常保留 `previous`；句柄始终关闭。
8. **同步即同步**：不伪装异步；大导入由调用方决定是否放入 Task Worker。

---

## 5. 目录结构

```text
src/PhpOffice/Excel/
├── AbstractExcelExport.php
├── AbstractExcelImport.php              # 导入模板方法
├── Exception/
│   ├── ExcelException.php
│   ├── ExcelExtensionMissingException.php
│   ├── ExcelExportException.php
│   ├── ExcelImportException.php
│   ├── InvalidExcelExportException.php
│   ├── InvalidExcelImportException.php
│   ├── InvalidExcelImportRowException.php
│   └── InvalidExcelRowException.php
├── Support/
│   ├── ColumnLetter.php                 # 导入/导出共用
│   ├── ExcelExtension.php               # 扩展守卫，导入/导出共用
│   ├── ExcelExportValidator.php
│   └── ExcelImportValidator.php
└── ValueObject/
    ├── ExcelExportContext.php
    ├── ExcelExportResult.php
    ├── ExcelImportResult.php
    ├── ExcelImportRow.php
    └── ExcelReadResult.php
```

---

## 6. 核心 API 设计

### 6.1 `AbstractExcelImport`

```php
abstract class AbstractExcelImport
{
    // 仅 import() 调用；read() 不需要实现
    protected function handleRow(ExcelImportRow $row): void;

    protected function sheetName(): ?string;          // null = 第一个 sheet
    protected function headerRow(): int;              // 1 基；0 = 无表头
    protected function dataStartRow(): int;           // 0 = 自动
    protected function expectedHeaders(): ?array;
    protected function useStringType(): bool;         // 默认 true
    protected function cellTypes(): array;            // 0 基列 => TYPE_*
    protected function skipEmptyRows(): bool;         // 默认 true
    protected function normalizeCells(array $cells, int $rowNumber): array;
    protected function afterImport(int $rowCount): void;

    final public function read(string $file): ExcelReadResult;
    final public function import(string $file): ExcelImportResult;
}
```

`read()` / `import()` 均为 `final`，共用同一套配置与读取流程；单个实例只能执行其中一个一次。行号约定：

- `headerRow()` / `dataStartRow()` / `ExcelImportRow::$rowNumber` / 结果中的 `headerRowNumber`、`lastRowNumber` 都是 **1 基 Excel 行号**；
- `cells` / `cellTypes()` 的列索引是 **0 基**；
- 错误里的 `columnIndex` 是 0 基，`columnLetter` 是 Excel 列字母，两者同时提供方便定位。

### 6.2 `ExcelImportRow`

```php
final readonly class ExcelImportRow
{
    public function __construct(
        public int $rowNumber,
        public array $cells,   // list<mixed>，已补齐列宽
        public array $values,  // header => value；无表头时 []
    ) {}

    public function cell(int $index, mixed $default = null): mixed;
    public function value(string $header, mixed $default = null): mixed;
    public function isEmpty(): bool;  // null 或空白字符串视为空
}
```

### 6.3 `ExcelImportResult` / `ExcelReadResult`

`import()` 返回 `ExcelImportResult`：

| 字段 | 含义 |
|---|---|
| `path` | `realpath()` 规范化后的文件路径 |
| `sheetName` | 实际读取的 sheet |
| `headers` | 归一化后的表头列表；无表头时 `[]` |
| `headerRowNumber` | 表头行号；0 = 无表头 |
| `rowCount` | 交给 `handleRow()` 的数据行数 |
| `skippedRowCount` | 因空行跳过的行数 |
| `lastRowNumber` | 最后读取到的 Excel 行号；空表为 0 |

`read()` 返回 `ExcelReadResult`，元数据字段含义相同，额外携带全部行：

| 字段 | 含义 |
|---|---|
| `rows` | `list<ExcelImportRow>`，全部已归一化的数据行 |
| `rowCount` | 数据行数，等于 `count($rows)` |

### 6.4 `ExcelImportValidator`

| 方法 | 规则 |
|---|---|
| `normalizeFile()` | 非空、`realpath` 存在且为文件、可读、大小 > 0 |
| `resolveSheetName()` | 过滤空名称；`null` 取第一个；指定名称必须存在，错误列出可用 sheet |
| `normalizeHeaderRow()` | `0..1,048,576` |
| `resolveDataStartRow()` | `0` 自动推导；显式值必须大于表头行且不超过行上限 |
| `normalizeExpectedHeaders()` | `null` 或非空列表；值转字符串、`trim`、去重校验 |
| `normalizeHeaderRowCells()` | 空表头行拒绝；逐列校验类型、空值、长度、重复 |
| `assertHeaderMatches()` | 严格逐列比较，错误同时输出期望与实际表头 |
| `normalizeDataCells()` | 短行补 `''`；非空多余列抛行异常；纯空白多余列截断 |
| `normalizeNormalizedCells()` | `normalizeCells()` 必须返回列表；有表头时列数必须一致 |
| `normalizeCellTypes()` | 0 基列在 `0..16,383`；类型仅允许 `TYPE_STRING/INT/DOUBLE/TIMESTAMP` |

### 6.5 异常体系

```text
RuntimeException
└── ExcelException
    ├── ExcelExtensionMissingException
    ├── ExcelExportException
    ├── ExcelImportException
    │   └── InvalidExcelImportException
    │       └── InvalidExcelImportRowException   // rowNumber / columnIndex / columnLetter
    ├── InvalidExcelExportException
    │   └── InvalidExcelRowException
    └── ...
```

- 配置与文件级错误：`InvalidExcelImportException`；
- 单行错误：`InvalidExcelImportRowException`（附带行号、列号）；
- xlswriter 自身异常：包装为 `ExcelImportException('Excel import failed: ...')`，`previous` 保留原异常；
- 业务钩子异常：原样传播，不包装。

---

## 7. 导入流程与校验

### 7.1 `read()` / `import()` 执行流程

两者共享私有生成器 `readRows()`：

```text
单实例检查
  → ExcelExtension::assertLoaded()
  → 校验文件（realpath / 可读 / 非空）
  → 校验 headerRow / dataStartRow / expectedHeaders / cellTypes
  → new Excel(['path' => dirname]) + openFile(basename)
  → sheetList() 校验并 openSheet()
  → setGlobalType(TYPE_STRING)（默认）
  → setType(cellTypes)（可选覆盖）
  → while (nextRow() !== null)
       → currentRow++（1 基）
       → 表头行：归一化表头 + expectedHeaders 比较
       → 早于 dataStartRow：跳过
       → 行宽归一化（补齐 / 多余列检查）
       → 空行：按 skipEmptyRows 跳过或交给业务
       → normalizeCells() → 宽度校验
       → array_combine(headers, cells) 生成 values
       → yield new ExcelImportRow(...)
  → 表头行未出现则抛异常
  → read(): 收集全部行 → ExcelReadResult
  → import(): 逐行 handleRow() → afterImport(rowCount) → ExcelImportResult
  finally close()
```

`read()` 只是把生成器产出的行收集进结果，不调用 `handleRow()` / `afterImport()`；`import()` 逐行消费，内存不随总行数增长。

### 7.2 表头规则

- 表头单元格支持 `string|int|float`，统一 `trim()` 后作为键；
- 空表头、重复表头、超过 32,767 字符拒绝；
- `expectedHeaders()` 也走同一套归一化，因此 `[1, 2.5, '名称']` 会与文件中的 `['1', '2.5', '名称']` 匹配；
- 表头只出现一次；`dataStartRow` 必须大于表头行，避免把表头当数据或把数据当表头。

### 7.3 行宽与空行

| 场景 | 行为 |
|---|---|
| 行短于表头 | 补 `''` |
| 行等于表头宽度 | 原样 |
| 行多出空白列 | 截断，不报错 |
| 行多出非空列 | `InvalidExcelImportRowException`，列号指向第一个多余非空列 |
| 全空行 | `skipEmptyRows() === true` 时跳过并计数；否则补齐后交给 `handleRow()` |
| 无表头 | 不补齐、不截断，行宽可变，`values` 为 `[]` |

### 7.4 类型策略

- 默认 `useStringType() === true`：所有单元格按原始文本读取，保留前导零和长编号；
- `cellTypes()` 按 0 基列覆盖，可混合使用：编号 `TYPE_STRING`、金额 `TYPE_DOUBLE`、日期 `TYPE_TIMESTAMP`；
- `TYPE_TIMESTAMP` 只对真实日期单元格生效；日期列在默认字符串模式下是 Excel 序列号（如 `46317.416...`），不是时间戳；
- `TYPE_DOUBLE` 返回 `float`，整数单元格也是 `3.0`；
- `null` 只可能来自类型化后的空单元格；行宽归一化补位统一用 `''`。

### 7.5 异常与资源

- 配置校验在打开文件前完成，避免无意义的句柄创建；
- `openFile()` 失败（损坏文件、CSV）由 `catch (\Vtiful\Kernel\Exception)` 包装；
- 业务异常不捕获，直接向上抛；`finally` 仍然 `close()`；
- 单实例标记在流程开始时置位，`read()` / `import()` 共用该标记，失败后同样不可复用，避免游标状态不可预测。

---

## 8. 落库用法

推荐在 `handleRow()` 中攒批，在 `afterImport()` 中 flush 最后一批：

```php
final class OrderImport extends AbstractExcelImport
{
    /** @var list<array<string, mixed>> */
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
            'order_no'   => $row->value('订单号'),
            'amount'     => $row->value('金额'),
            'status'     => trim((string) $row->value('状态')),
            'ordered_at' => $row->value('下单时间'),
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
```

注意：

- 单行业务校验失败时抛出带 `rowNumber` 的异常，调用方可直接提示「第 N 行」；
- 若整批插入失败，异常会中断导入；需要部分成功语义时，由业务在 `handleRow()` 内自行记录失败行并继续；
- 文件生命周期（临时文件清理）由调用方负责，基类只负责关闭 xlswriter 句柄。

---

## 9. 性能、内存与协程

- `nextRow()` 是流式接口，`import()` 的内存占用与文件总行数无关；测试 5,000 行导入约 70 ms（容器实测，含模拟 500 行批量落库）；
- `read()` 会保留全部 `ExcelImportRow`，内存随行数线性增长，只适合小文件或明确需要整表在内存中处理的场景；
- 不要在 `handleRow()` 中无限累积数据；需要全量处理时按批 flush；
- xlswriter 是同步 C 扩展，在协程 Worker 中会阻塞当前协程；大文件应放入 Task Worker 或独立进程；
- 每个导入实例持有独立句柄，可在不同协程中并发导入不同文件；测试覆盖 4 协程并发读取；
- 不要跨协程共享同一个导入实例（单实例一次性，且游标非线程安全）。

---

## 10. 测试方案与结果

### 10.1 单元测试

`tests/PhpOffice/Excel/ExcelImportTest.php`（命名空间 `Swoolefy\Library\Tests\PhpOffice\Excel`）：

```bash
docker exec swoolefy-php85-v62 sh -lc 'cd /home/wwwroot/library && vendor/bin/phpunit tests/PhpOffice/Excel/ExcelImportTest.php'
```

结果：**OK (41 tests, 206 assertions)**。覆盖：

| 组 | 场景 |
|---|---|
| 1 | 基本导入、表头映射、前导零、时间戳、`afterImport` |
| 2 | 空行跳过 / 保留、空行计数、最后行号 |
| 3 | 无表头、行宽可变、`cell()` 默认值 |
| 4 | 自定义表头行 / 数据起始行、跳过说明行 |
| 5 | `expectedHeaders` 匹配 / 不匹配 / 无表头冲突 |
| 6 | 空表头行、空表头列、重复表头、纯空白表头、表头行缺失 |
| 7 | 只有表头（0 数据行） |
| 8 | 多 sheet 选择、缺失 sheet、空 sheet 名 |
| 9 | 空路径 / 不存在 / 目录 / 空文件；`.dat` 内含 xlsx |
| 10 | 默认字符串、原生类型、按列覆盖、混合模式 |
| 11 | 非空多余列报错、空白多余列允许、短行补齐 |
| 12 | `normalizeCells` 归一化、行号透传、非法返回值 |
| 13 | `ExcelImportRow` 辅助方法 |
| 14 | 业务异常透传、`afterImport` 不执行、失败后不可复用 |
| 15 | 成功导入后实例不可复用 |
| 16 | 非法配置（表头行、起始行、列类型、期望表头、sheet 名） |
| 17 | 数字表头、表头空白修剪 |
| 18 | 损坏工作簿包装 `ExcelImportException` |
| 19 | 5,000 行流式导入 |
| 20 | `read()` 全量读取、元数据、不触发 `handleRow()` / `afterImport()` |
| 21 | 只读子类不实现 `handleRow()`；`import()` 未实现时抛 `LogicException` |
| 22 | `read()` / `import()` 共享一次性限制 |
| 23 | `read()` 的 xlswriter 异常包装与 `previous` 保留 |

Excel 模块全量回归：`vendor/bin/phpunit tests/PhpOffice/Excel` → **OK (86 tests, 415 assertions)**。

### 10.2 swoolefy Test 端到端

入口：`GET /api/excel/import/verify`，代码在 `swoolefy/Test/Common/ExcelImportVerify.php`。结果：**pass: true，cleanup: ok，6/6 场景通过**：

| 场景 | 结果 |
|---|---|
| `import_5000_rows_batched` | 5,000 行读取并模拟批量落库，无重复/丢失；首行 `00001`、末行 `05000` 前导零保留；约 70 ms |
| `header_mismatch_and_reuse` | 表头不匹配被拦截，`afterImport` 未执行；失败后实例复用被拒绝 |
| `concurrent_imports` | 4 协程并发导入两个文件（500 / 700 行），行序与计数正确；约 39 ms |
| `typed_cells_and_normalize` | `TYPE_STRING/DOUBLE/TIMESTAMP` 按列生效，`normalizeCells` 修剪空白 |
| `empty_rows` | 默认跳过 2 个空行；关闭后 4 行全部进入业务且 `isEmpty()` 正确 |
| `read_all_rows` | 500 行整表读取，首末行、表头、行号正确；不触发 `handleRow()` / `afterImport()`；只读子类无需实现 `handleRow()`；`read()` 后 `import()` 被拒绝；约 16 ms |

导出 E2E 回归 `GET /api/excel/verify`：**pass: true，8/8**。

---

## 11. 验收清单

- [x] `read()` / `import()` 均为 `final`，固定文件生命周期；单实例一次性。
- [x] `read()` 返回全部 `ExcelImportRow` 与元数据，只读子类无需实现 `handleRow()`。
- [x] 流式读取，不调用 `getSheetData()`；5,000 行 E2E 通过。
- [x] 默认字符串模式保留前导零；按列类型覆盖可用。
- [x] 表头映射、`expectedHeaders` 模板校验、空/重复表头拒绝。
- [x] 1 基 Excel 行号与 0 基列号语义明确，异常携带定位信息。
- [x] 空行跳过/保留、短行补齐、多余列策略明确。
- [x] xlswriter 异常包装、业务异常透传、句柄始终关闭。
- [x] 单元测试 41 项 / 206 断言通过；Excel 全量回归 86 项 / 415 断言通过。
- [x] swoolefy Test E2E 6/6 通过，临时文件清理完成。
- [x] `src/PhpOffice/Excel/README.md`、根 README、本方案同步更新。

---

## 12. 实施记录（2026-10-22）

| 阶段 | 内容 |
|---|---|
| P1 | `ExcelImportValidator`、`ExcelImportRow`、`ExcelImportResult`、3 个导入异常类 |
| P2 | `AbstractExcelImport` 模板流程、表头映射、类型/行宽/空行处理、`normalizeCells` 与 `afterImport` 钩子 |
| P3 | 抽取共用 `ExcelExtension`，导出守卫改为委托；`ExcelImportTest` 19 组 35 tests |
| P4 | swoolefy `ExcelImportVerify` 5 场景、控制器与路由、模块/根 README、本技术方案 |
| P5 | 新增 `read()` 总读取函数与 `ExcelReadResult`，抽取共享 `readRows()` 生成器；`handleRow()` 改为默认抛 `LogicException`，只读子类无需实现；补 4 组单测与 `read_all_rows` E2E |

实施中发现并修正：

- E2E 断言最初把 `TYPE_DOUBLE` 的整数结果写成 `3`，实际 xlswriter 返回 `3.0`；按真实类型修正断言并写入文档；
- `getSheetData()` 会耗尽 `nextRow()` 游标，验证脚本必须先取一种读取方式；导入实现与测试均只使用 `nextRow()`。

---

## 13. 风险与对策

| 编号 | 风险 | 影响 | 对策 |
|---|---|---|---|
| R1 | 超大文件长时间占用 Worker | 阻塞其它协程 | 放入 Task Worker；分批落库；设置业务超时 |
| R2 | 用户上传损坏文件 | xlswriter 抛异常 | 包装为 `ExcelImportException`，保留 `previous` |
| R3 | 模板表头变更 | 数据错列 | `expectedHeaders()` 严格校验，失败即中止 |
| R4 | 编号前导零丢失 | 业务主键错误 | 默认 `TYPE_STRING`；仅在明确数值列覆盖 |
| R5 | 日期列未声明类型 | 得到 Excel 序列号 | 文档明确 `TYPE_TIMESTAMP`；业务可自行转换 |
| R6 | 业务落库异常 | 部分数据已写入 | 由业务控制事务/幂等；基类只保证停止读取并关闭句柄 |
| R7 | 多余列/空行造成脏数据 | 插入异常或错位 | 非空多余列直接报错；空行默认跳过 |
| R8 | 单实例跨请求复用 | 游标状态不可预期 | 单实例一次性，复用直接抛异常 |

---

## 14. 参考

- xlswriter 文档：<https://xlswriter-docs.viest.me/>
- 导出方案：[library-6.x-PhpOffice-Xlswriter-Excel导出技术方案.md](library-6.x-PhpOffice-Xlswriter-Excel导出技术方案.md)
- 模块使用文档：[src/PhpOffice/Excel/README.md](../src/PhpOffice/Excel/README.md)
