<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\PhpOffice\Excel;

use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoolefy\Library\PhpOffice\Excel\AbstractExcelImport;
use Swoolefy\Library\PhpOffice\Excel\Exception\ExcelImportException;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelImportException;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelImportRowException;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelImportRow;
use Vtiful\Kernel\Excel;

/**
 * PhpOffice Excel 导入回归测试。
 *
 * 需要 ext-xlswriter；所有文件写入 sys_get_temp_dir() 下的唯一目录，tearDown 清理。
 */
final class ExcelImportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!extension_loaded('xlswriter') || !class_exists(Excel::class)) {
            $this->markTestSkipped('ext-xlswriter required');
        }

        $this->directory = sys_get_temp_dir() . '/library-excel-import-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            $this->removeDirectory($this->directory);
        }
    }

    // ------------------------------------------------------------------
    // 1. 基本导入：表头映射、前导零、日期类型
    // ------------------------------------------------------------------

    public function testBasicImportMapsHeadersAndPreservesLeadingZeros(): void
    {
        $timestamp = 1792653600;
        $file = $this->createWorkbook('basic.xlsx', [[
            'name' => '数据',
            'cells' => [
                0 => ['编号', '名称', '金额', '日期'],
                1 => ['00123', '苹果', 12.5],
                2 => ['A002', '香蕉', 3],
            ],
            'dates' => [[1, 3, $timestamp], [2, 3, $timestamp + 86400]],
        ]]);

        $import = new ConfigurableExcelImport(['cellTypes' => [3 => Excel::TYPE_TIMESTAMP]]);
        $result = $import->import($file);

        $this->assertSame(realpath($file), $result->path);
        $this->assertSame('数据', $result->sheetName);
        $this->assertSame(['编号', '名称', '金额', '日期'], $result->headers);
        $this->assertSame(1, $result->headerRowNumber);
        $this->assertSame(2, $result->rowCount);
        $this->assertSame(0, $result->skippedRowCount);
        $this->assertSame(3, $result->lastRowNumber);
        $this->assertSame([2], $import->afterCounts);

        $this->assertCount(2, $import->rows);
        $this->assertSame(2, $import->rows[0]->rowNumber);
        $this->assertSame(['00123', '苹果', '12.5', $timestamp], $import->rows[0]->cells);
        $this->assertSame('苹果', $import->rows[0]->value('名称'));
        $this->assertSame($timestamp + 86400, $import->rows[1]->value('日期'));
        $this->assertSame('missing', $import->rows[0]->value('不存在', 'missing'));
        $this->assertFalse($import->rows[0]->isEmpty());
    }

    // ------------------------------------------------------------------
    // 2. 空行：默认跳过并计数；关闭后交给 handleRow
    // ------------------------------------------------------------------

    public function testEmptyRowsAreSkippedOrImported(): void
    {
        $file = $this->createWorkbook('empty_rows.xlsx', [[
            'name' => 'S',
            'cells' => [
                0 => ['编号', '名称'],
                1 => ['A1', 'x'],
                // Excel 第 3 行留空
                3 => ['   ', '   '],
                4 => ['A2', 'y'],
            ],
        ]]);

        $skipping = new ConfigurableExcelImport();
        $result = $skipping->import($file);

        $this->assertSame(2, $result->rowCount);
        $this->assertSame(2, $result->skippedRowCount);
        $this->assertSame(5, $result->lastRowNumber);
        $this->assertSame(
            [2, 5],
            array_map(static fn (ExcelImportRow $row): int => $row->rowNumber, $skipping->rows)
        );

        $keeping = new ConfigurableExcelImport(['skipEmptyRows' => false]);
        $result = $keeping->import($file);

        $this->assertSame(4, $result->rowCount);
        $this->assertSame(0, $result->skippedRowCount);
        $this->assertSame(['', ''], $keeping->rows[1]->cells);
        $this->assertTrue($keeping->rows[1]->isEmpty());
        $this->assertSame(['   ', '   '], $keeping->rows[2]->cells);
        $this->assertTrue($keeping->rows[2]->isEmpty());
    }

    // ------------------------------------------------------------------
    // 3. 无表头：行宽可变，values 为空
    // ------------------------------------------------------------------

    public function testNoHeaderModeAcceptsVariableWidthRows(): void
    {
        $file = $this->createWorkbook('no_header.xlsx', [[
            'name' => 'S',
            'cells' => [
                0 => ['A1', 'B1'],
                1 => ['A2', 'B2', 'C2'],
            ],
        ]]);

        $import = new ConfigurableExcelImport(['headerRow' => 0]);
        $result = $import->import($file);

        $this->assertSame([], $result->headers);
        $this->assertSame(0, $result->headerRowNumber);
        $this->assertSame(2, $result->rowCount);
        $this->assertSame(2, $result->lastRowNumber);
        $this->assertSame(1, $import->rows[0]->rowNumber);
        $this->assertSame(['A1', 'B1'], $import->rows[0]->cells);
        $this->assertSame([], $import->rows[0]->values);
        $this->assertSame(['A2', 'B2', 'C2'], $import->rows[1]->cells);
        $this->assertSame('B2', $import->rows[1]->cell(1));
        $this->assertSame('fallback', $import->rows[0]->cell(5, 'fallback'));
    }

    // ------------------------------------------------------------------
    // 4. 自定义表头行与数据起始行
    // ------------------------------------------------------------------

    public function testCustomHeaderRowAndDataStartRow(): void
    {
        $file = $this->createWorkbook('offset.xlsx', [[
            'name' => 'S',
            'cells' => [
                0 => ['2026 年导入模板'],
                2 => ['编号', '名称'],
                3 => ['说明：不要修改表头'],
                4 => ['A1', 'x'],
                5 => ['A2', 'y'],
            ],
        ]]);

        $import = new ConfigurableExcelImport(['headerRow' => 3, 'dataStartRow' => 5]);
        $result = $import->import($file);

        $this->assertSame(['编号', '名称'], $result->headers);
        $this->assertSame(3, $result->headerRowNumber);
        $this->assertSame(2, $result->rowCount);
        $this->assertSame(6, $result->lastRowNumber);
        $this->assertSame(
            [5, 6],
            array_map(static fn (ExcelImportRow $row): int => $row->rowNumber, $import->rows)
        );
        $this->assertSame(['A1', 'x'], $import->rows[0]->cells);
    }

    // ------------------------------------------------------------------
    // 5. expectedHeaders：匹配、不匹配、缺少表头行
    // ------------------------------------------------------------------

    public function testExpectedHeadersAreValidated(): void
    {
        $file = $this->createWorkbook('expected.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['编号', '名称'], 1 => ['A1', 'x']],
        ]]);

        $matching = new ConfigurableExcelImport(['expectedHeaders' => [' 编号 ', '名称']]);
        $this->assertSame(1, $matching->import($file)->rowCount);

        $mismatch = new ConfigurableExcelImport(['expectedHeaders' => ['编号', '姓名']]);
        try {
            $mismatch->import($file);
            $this->fail('Expected InvalidExcelImportException');
        } catch (InvalidExcelImportException $e) {
            $this->assertStringContainsString('header mismatch', $e->getMessage());
            $this->assertStringContainsString('编号 | 姓名', $e->getMessage());
            $this->assertStringContainsString('编号 | 名称', $e->getMessage());
        }
        $this->assertSame([], $mismatch->rows);
        $this->assertSame([], $mismatch->afterCounts);

        $noHeader = new ConfigurableExcelImport(['headerRow' => 0, 'expectedHeaders' => ['编号']]);
        try {
            $noHeader->import($file);
            $this->fail('Expected InvalidExcelImportException');
        } catch (InvalidExcelImportException $e) {
            $this->assertStringContainsString('requires headerRow()', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 6. 非法表头：空行、空列、重复、纯空白
    // ------------------------------------------------------------------

    public function testInvalidHeaderRowsAreRejected(): void
    {
        $cases = [
            'blank row' => [[1 => ['A', 'B']], 1],
            'empty middle cell' => [[0 => ['A', '', 'C'], 1 => ['1', '2', '3']], 1],
            'duplicate' => [[0 => ['A', 'A'], 1 => ['1', '2']], 1],
            'whitespace only' => [[0 => ['A', '   '], 1 => ['1', '2']], 1],
        ];

        $index = 0;
        foreach ($cases as $label => [$cells, $headerRow]) {
            $file = $this->createWorkbook(
                'bad_header_' . $index++ . '.xlsx',
                [['name' => 'S', 'cells' => $cells]]
            );
            $import = new ConfigurableExcelImport(['headerRow' => $headerRow]);

            try {
                $import->import($file);
                $this->fail(sprintf('Expected InvalidExcelImportException for %s', $label));
            } catch (InvalidExcelImportException $e) {
                $this->assertStringContainsString('header', strtolower($e->getMessage()), $label);
            }

            $this->assertSame([], $import->rows, $label);
            $this->assertSame([], $import->afterCounts, $label);
        }
    }

    public function testMissingHeaderRowIsDetected(): void
    {
        $file = $this->createWorkbook('missing_header.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['A', 'B'], 1 => ['1', '2']],
        ]]);

        $import = new ConfigurableExcelImport(['headerRow' => 3]);
        try {
            $import->import($file);
            $this->fail('Expected InvalidExcelImportException');
        } catch (InvalidExcelImportException $e) {
            $this->assertStringContainsString('Header row 3 does not exist', $e->getMessage());
        }

        $this->assertSame([], $import->afterCounts);
    }

    // ------------------------------------------------------------------
    // 7. 只有表头 / 没有数据行
    // ------------------------------------------------------------------

    public function testHeaderOnlySheetYieldsZeroRows(): void
    {
        $file = $this->createWorkbook('header_only.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['编号', '名称']],
        ]]);

        $import = new ConfigurableExcelImport();
        $result = $import->import($file);

        $this->assertSame(['编号', '名称'], $result->headers);
        $this->assertSame(0, $result->rowCount);
        $this->assertSame(0, $result->skippedRowCount);
        $this->assertSame(1, $result->lastRowNumber);
        $this->assertSame([0], $import->afterCounts);
        $this->assertSame([], $import->rows);
    }

    // ------------------------------------------------------------------
    // 8. sheet 选择与缺失
    // ------------------------------------------------------------------

    public function testSheetSelectionAndMissingSheet(): void
    {
        $file = $this->createWorkbook('sheets.xlsx', [
            [
                'name' => '第一页',
                'cells' => [0 => ['页'], 1 => ['A']],
            ],
            [
                'name' => '目标',
                'cells' => [0 => ['编号'], 1 => ['B1'], 2 => ['B2']],
            ],
        ]);

        $default = new ConfigurableExcelImport();
        $result = $default->import($file);
        $this->assertSame('第一页', $result->sheetName);
        $this->assertSame(['页'], $result->headers);
        $this->assertSame(1, $result->rowCount);

        $target = new ConfigurableExcelImport(['sheetName' => '目标']);
        $result = $target->import($file);
        $this->assertSame('目标', $result->sheetName);
        $this->assertSame(['编号'], $result->headers);
        $this->assertSame(2, $result->rowCount);

        $missing = new ConfigurableExcelImport(['sheetName' => '不存在']);
        try {
            $missing->import($file);
            $this->fail('Expected InvalidExcelImportException');
        } catch (InvalidExcelImportException $e) {
            $this->assertStringContainsString('available sheets: 第一页, 目标', $e->getMessage());
        }

        $blank = new ConfigurableExcelImport(['sheetName' => '   ']);
        try {
            $blank->import($file);
            $this->fail('Expected InvalidExcelImportException');
        } catch (InvalidExcelImportException $e) {
            $this->assertStringContainsString('null or a non-empty sheet name', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 9. 文件校验 + 按内容识别 xlsx
    // ------------------------------------------------------------------

    public function testInvalidImportFileIsRejected(): void
    {
        $emptyPath = $this->directory . '/empty.xlsx';
        file_put_contents($emptyPath, '');

        $cases = [
            '' => 'must not be empty',
            $this->directory . '/missing.xlsx' => 'does not exist',
            $this->directory => 'does not exist',
            $emptyPath => 'is empty',
        ];

        foreach ($cases as $path => $message) {
            $import = new ConfigurableExcelImport();
            try {
                $import->import((string) $path);
                $this->fail(sprintf('Expected InvalidExcelImportException for [%s]', $path));
            } catch (InvalidExcelImportException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $source = $this->createWorkbook('source.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['编号'], 1 => ['A1']],
        ]]);
        $renamed = $this->directory . '/renamed.dat';
        copy($source, $renamed);

        $import = new ConfigurableExcelImport();
        $this->assertSame(1, $import->import($renamed)->rowCount);
    }

    // ------------------------------------------------------------------
    // 10. 读取类型：字符串默认、原始类型、按列覆盖
    // ------------------------------------------------------------------

    public function testCellReadingTypes(): void
    {
        $timestamp = 1792653600;
        $file = $this->createWorkbook('types.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['编号', '金额', '日期'], 1 => ['00123', 12.5]],
            'dates' => [[1, 2, $timestamp]],
        ]]);

        $strings = new ConfigurableExcelImport();
        $strings->import($file);
        $this->assertSame('00123', $strings->rows[0]->cells[0]);
        $this->assertSame('12.5', $strings->rows[0]->cells[1]);
        $this->assertIsString($strings->rows[0]->cells[2]);

        $native = new ConfigurableExcelImport(['useStringType' => false]);
        $native->import($file);
        $this->assertSame(123, $native->rows[0]->cells[0]);
        $this->assertSame(12.5, $native->rows[0]->cells[1]);
        $this->assertIsFloat($native->rows[0]->cells[2]);

        $typed = new ConfigurableExcelImport([
            'cellTypes' => [
                0 => Excel::TYPE_STRING,
                1 => Excel::TYPE_DOUBLE,
                2 => Excel::TYPE_TIMESTAMP,
            ],
        ]);
        $typed->import($file);
        $this->assertSame(['00123', 12.5, $timestamp], $typed->rows[0]->cells);

        $mixed = new ConfigurableExcelImport([
            'useStringType' => false,
            'cellTypes' => [0 => Excel::TYPE_STRING, 2 => Excel::TYPE_TIMESTAMP],
        ]);
        $mixed->import($file);
        $this->assertSame('00123', $mixed->rows[0]->cells[0]);
        $this->assertSame(12.5, $mixed->rows[0]->cells[1]);
        $this->assertSame($timestamp, $mixed->rows[0]->cells[2]);
    }

    // ------------------------------------------------------------------
    // 11. 多余列：非空报错，空白允许；短行补齐
    // ------------------------------------------------------------------

    public function testExtraColumnsAreRejectedUnlessEmpty(): void
    {
        $file = $this->createWorkbook('extra.xlsx', [[
            'name' => 'S',
            'cells' => [
                0 => ['编号', '名称'],
                1 => ['A1', 'x', '多余'],
            ],
        ]]);

        $bad = new ConfigurableExcelImport();
        try {
            $bad->import($file);
            $this->fail('Expected InvalidExcelImportRowException');
        } catch (InvalidExcelImportRowException $e) {
            $this->assertSame(2, $e->rowNumber);
            $this->assertSame(3, $e->columnIndex);
            $this->assertSame('C', $e->columnLetter);
            $this->assertStringContainsString('no matching header', $e->getMessage());
        }
        $this->assertSame([], $bad->afterCounts);

        $tolerantFile = $this->createWorkbook('extra_tolerant.xlsx', [[
            'name' => 'S',
            'cells' => [
                0 => ['编号', '名称'],
                1 => ['A2', 'y', '   '],
                2 => ['A3'],
            ],
        ]]);

        $tolerant = new ConfigurableExcelImport();
        $result = $tolerant->import($tolerantFile);

        $this->assertSame(2, $result->rowCount);
        $this->assertSame(['A2', 'y'], $tolerant->rows[0]->cells);
        $this->assertSame(['A3', ''], $tolerant->rows[1]->cells);
    }

    // ------------------------------------------------------------------
    // 12. normalizeCells 钩子
    // ------------------------------------------------------------------

    public function testNormalizeCellsHook(): void
    {
        $file = $this->createWorkbook('normalize.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['编号', '名称'], 1 => [' abc ', ' def ']],
        ]]);

        $seenRows = [];
        $import = new ConfigurableExcelImport([
            'normalizeCells' => static function (array $cells, int $rowNumber) use (&$seenRows): array {
                $seenRows[] = $rowNumber;

                return array_map(
                    static fn ($value) => is_string($value) ? strtoupper(trim($value)) : $value,
                    $cells
                );
            },
        ]);

        $import->import($file);

        $this->assertSame([2], $seenRows);
        $this->assertSame(['ABC', 'DEF'], $import->rows[0]->cells);
        $this->assertSame(['编号' => 'ABC', '名称' => 'DEF'], $import->rows[0]->values);
    }

    public function testNormalizeCellsMustReturnSameWidthList(): void
    {
        $file = $this->createWorkbook('normalize_invalid.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['编号', '名称'], 1 => ['A1', 'x']],
        ]]);

        $cases = [
            'short list' => [
                static fn (array $cells): array => [$cells[0]],
                'returned 1 columns',
            ],
            'non-list' => [
                static fn (array $cells): array => ['a' => $cells[0], 'b' => $cells[1]],
                'must return a list',
            ],
        ];

        foreach ($cases as $label => [$hook, $message]) {
            $import = new ConfigurableExcelImport(['normalizeCells' => $hook]);

            try {
                $import->import($file);
                $this->fail(sprintf('Expected InvalidExcelImportRowException for %s', $label));
            } catch (InvalidExcelImportRowException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $label);
                $this->assertSame(2, $e->rowNumber, $label);
            }

            $this->assertSame([], $import->afterCounts, $label);
        }
    }

    // ------------------------------------------------------------------
    // 13. ExcelImportRow 辅助方法
    // ------------------------------------------------------------------

    public function testImportRowHelpers(): void
    {
        $row = new ExcelImportRow(5, ['a', null], ['h' => 'a']);

        $this->assertSame('a', $row->cell(0));
        $this->assertNull($row->cell(1));
        $this->assertSame('default', $row->cell(2, 'default'));
        $this->assertSame('a', $row->value('h'));
        $this->assertSame('default', $row->value('missing', 'default'));
        $this->assertFalse($row->isEmpty());

        $this->assertTrue((new ExcelImportRow(6, ['', '   ', null], []))->isEmpty());
        $this->assertTrue((new ExcelImportRow(7, [], []))->isEmpty());
    }

    // ------------------------------------------------------------------
    // 14. 业务异常透传、afterImport 不执行、失败后不可复用
    // ------------------------------------------------------------------

    public function testHandleRowExceptionPropagatesAndSkipsAfterImport(): void
    {
        $file = $this->createWorkbook('business_throw.xlsx', [[
            'name' => 'S',
            'cells' => [
                0 => ['编号', '名称'],
                1 => ['A1', 'x'],
                2 => ['A2', 'y'],
            ],
        ]]);

        $handled = 0;
        $import = new ConfigurableExcelImport([
            'handleRow' => static function (ExcelImportRow $row) use (&$handled): void {
                $handled++;
                if ($handled === 2) {
                    throw new DomainException('row boom');
                }
            },
        ]);

        try {
            $import->import($file);
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertSame('row boom', $e->getMessage());
        }

        $this->assertSame(2, $handled);
        $this->assertSame([], $import->afterCounts);

        try {
            $import->import($file);
            $this->fail('Expected ExcelImportException');
        } catch (ExcelImportException $e) {
            $this->assertStringContainsString('already been imported', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 15. 实例二次导入
    // ------------------------------------------------------------------

    public function testImportInstanceCannotBeReused(): void
    {
        $file = $this->createWorkbook('once.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['编号'], 1 => ['A1']],
        ]]);

        $import = new ConfigurableExcelImport();
        $result = $import->import($file);

        try {
            $import->import($file);
            $this->fail('Expected ExcelImportException');
        } catch (ExcelImportException $e) {
            $this->assertStringContainsString('already been imported', $e->getMessage());
        }

        $this->assertFileExists($result->path);
    }

    // ------------------------------------------------------------------
    // 16. 非法配置
    // ------------------------------------------------------------------

    #[DataProvider('invalidConfigProvider')]
    public function testInvalidConfigurationsAreRejected(array $options, string $message): void
    {
        $file = $this->createWorkbook('config.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['A'], 1 => ['1']],
        ]]);

        $import = new ConfigurableExcelImport($options);

        try {
            $import->import($file);
            $this->fail('Expected InvalidExcelImportException');
        } catch (InvalidExcelImportException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame([], $import->rows);
        $this->assertSame([], $import->afterCounts);
    }

    public static function invalidConfigProvider(): array
    {
        return [
            'headerRow negative' => [['headerRow' => -1], 'headerRow()'],
            'headerRow too large' => [['headerRow' => 1048577], 'headerRow()'],
            'dataStartRow negative' => [['dataStartRow' => -1], 'dataStartRow()'],
            'dataStartRow too large' => [
                ['headerRow' => 0, 'dataStartRow' => 1048577],
                'dataStartRow()',
            ],
            'dataStartRow before header' => [
                ['headerRow' => 2, 'dataStartRow' => 2],
                'greater than headerRow()',
            ],
            'cellTypes string key' => [
                ['cellTypes' => ['x' => Excel::TYPE_STRING]],
                'column index',
            ],
            'cellTypes negative column' => [
                ['cellTypes' => [-1 => Excel::TYPE_STRING]],
                'column index',
            ],
            'cellTypes column too large' => [
                ['cellTypes' => [16384 => Excel::TYPE_STRING]],
                'column index',
            ],
            'cellTypes unsupported constant' => [
                ['cellTypes' => [0 => 3]],
                'must be one of',
            ],
            'cellTypes non-int value' => [
                ['cellTypes' => [0 => '1']],
                'must be one of',
            ],
            'expectedHeaders empty' => [
                ['expectedHeaders' => []],
                'non-empty list',
            ],
            'expectedHeaders empty item' => [
                ['expectedHeaders' => ['A', '']],
                'must not be empty',
            ],
            'expectedHeaders duplicate' => [
                ['expectedHeaders' => ['A', 'A']],
                'duplicates header',
            ],
            'expectedHeaders without header row' => [
                ['headerRow' => 0, 'expectedHeaders' => ['A']],
                'requires headerRow()',
            ],
            'blank sheetName' => [
                ['sheetName' => '   '],
                'null or a non-empty sheet name',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // 17. 数字表头与空白修剪
    // ------------------------------------------------------------------

    public function testNumericAndPaddedHeadersAreNormalized(): void
    {
        $file = $this->createWorkbook('numeric_headers.xlsx', [[
            'name' => 'S',
            'cells' => [0 => [1, 2.5, ' 名称 '], 1 => ['a', 'b', 'c']],
        ]]);

        $import = new ConfigurableExcelImport(['expectedHeaders' => [1, 2.5, '名称']]);
        $result = $import->import($file);

        $this->assertSame(['1', '2.5', '名称'], $result->headers);
        $this->assertSame('a', $import->rows[0]->value('1'));
        $this->assertSame('b', $import->rows[0]->value('2.5'));
        $this->assertSame('c', $import->rows[0]->value('名称'));
    }

    // ------------------------------------------------------------------
    // 18. 非法工作簿内容被包装
    // ------------------------------------------------------------------

    public function testInvalidWorkbookIsWrapped(): void
    {
        $path = $this->directory . '/garbage.xlsx';
        file_put_contents($path, 'not an xlsx file');

        $import = new ConfigurableExcelImport();

        try {
            $import->import($path);
            $this->fail('Expected ExcelImportException');
        } catch (ExcelImportException $e) {
            $this->assertStringContainsString('Excel import failed', $e->getMessage());
            $this->assertInstanceOf(\Vtiful\Kernel\Exception::class, $e->getPrevious());
        }

        $this->assertSame([], $import->afterCounts);
    }

    // ------------------------------------------------------------------
    // 19. 大文件逐行读取
    // ------------------------------------------------------------------

    public function testLargeSheetStreamsAllRows(): void
    {
        $excel = new Excel(['path' => $this->directory]);
        $excel->fileName('large.xlsx', 'S');
        $excel->header(['编号', '值']);

        $batch = [];
        for ($i = 1; $i <= 5000; $i++) {
            $batch[] = ['NO-' . $i, $i];
            if (count($batch) === 500) {
                $excel->data($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $excel->data($batch);
        }

        $path = $excel->output();
        $excel->close();

        $count = 0;
        $first = null;
        $last = null;
        $import = new ConfigurableExcelImport([
            'handleRow' => static function (ExcelImportRow $row) use (&$count, &$first, &$last): void {
                $count++;
                $first ??= $row->value('编号');
                $last = $row->value('编号');
            },
        ]);

        $result = $import->import($path);

        $this->assertSame(5000, $result->rowCount);
        $this->assertSame(5000, $count);
        $this->assertSame('NO-1', $first);
        $this->assertSame('NO-5000', $last);
        $this->assertSame(5001, $result->lastRowNumber);
        $this->assertSame([5000], $import->afterCounts);
    }

    // ------------------------------------------------------------------
    // 20. 总读取函数：一次返回全部数据行，不触发生命周期钩子
    // ------------------------------------------------------------------

    public function testReadLoadsAllRowsWithoutImportHooks(): void
    {
        $file = $this->createWorkbook('read_all.xlsx', [[
            'name' => 'S',
            'cells' => [
                0 => ['编号', '名称'],
                1 => ['001', 'a'],
                // Excel 第 3 行留空
                3 => ['003', 'c'],
            ],
        ]]);

        $import = new ConfigurableExcelImport();
        $result = $import->read($file);

        $this->assertSame(realpath($file), $result->path);
        $this->assertSame('S', $result->sheetName);
        $this->assertSame(['编号', '名称'], $result->headers);
        $this->assertSame(1, $result->headerRowNumber);
        $this->assertSame(2, $result->rowCount);
        $this->assertSame(1, $result->skippedRowCount);
        $this->assertSame(4, $result->lastRowNumber);
        $this->assertCount(2, $result->rows);
        $this->assertSame(
            [2, 4],
            array_map(static fn (ExcelImportRow $row): int => $row->rowNumber, $result->rows)
        );
        $this->assertSame('001', $result->rows[0]->value('编号'));
        $this->assertSame('c', $result->rows[1]->value('名称'));
        $this->assertSame([], $import->rows);
        $this->assertSame([], $import->afterCounts);
    }

    public function testReadAppliesDeclarativeConfiguration(): void
    {
        $timestamp = 1792653600;
        $file = $this->createWorkbook('read_typed.xlsx', [[
            'name' => '数据',
            'cells' => [
                0 => ['导入模板'],
                2 => ['金额', '日期'],
                3 => [12.5],
            ],
            'dates' => [[3, 1, $timestamp]],
        ]]);

        $import = new ConfigurableExcelImport([
            'sheetName' => '数据',
            'headerRow' => 3,
            'cellTypes' => [1 => Excel::TYPE_TIMESTAMP],
        ]);

        $result = $import->read($file);

        $this->assertSame(['金额', '日期'], $result->headers);
        $this->assertSame(3, $result->headerRowNumber);
        $this->assertSame(1, $result->rowCount);
        $this->assertSame(['12.5', $timestamp], $result->rows[0]->cells);
        $this->assertSame([], $import->afterCounts);
    }

    // ------------------------------------------------------------------
    // 21. 只读子类无需实现 handleRow()
    // ------------------------------------------------------------------

    public function testReadOnlySubclassDoesNotNeedHandleRow(): void
    {
        $file = $this->createWorkbook('read_only.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['编号'], 1 => ['A1']],
        ]]);

        $result = (new ReadOnlyExcelImport())->read($file);

        $this->assertSame(1, $result->rowCount);
        $this->assertSame('A1', $result->rows[0]->value('编号'));
    }

    public function testImportWithoutHandleRowThrowsLogicException(): void
    {
        $file = $this->createWorkbook('no_handle.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['编号'], 1 => ['A1']],
        ]]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('handleRow() must be implemented');

        (new ReadOnlyExcelImport())->import($file);
    }

    // ------------------------------------------------------------------
    // 22. read() / import() 共享一次性限制
    // ------------------------------------------------------------------

    public function testReadAndImportShareSingleUseGuard(): void
    {
        $file = $this->createWorkbook('read_once.xlsx', [[
            'name' => 'S',
            'cells' => [0 => ['编号'], 1 => ['A1']],
        ]]);

        $import = new ConfigurableExcelImport();
        $import->read($file);

        $this->expectException(ExcelImportException::class);
        $this->expectExceptionMessage('already been imported or read');

        $import->import($file);
    }

    // ------------------------------------------------------------------
    // 23. read() 的 xlswriter 异常带读取语义并被包装
    // ------------------------------------------------------------------

    public function testReadInvalidWorkbookIsWrapped(): void
    {
        $path = $this->directory . '/read-garbage.xlsx';
        file_put_contents($path, 'not an xlsx file');

        try {
            (new ReadOnlyExcelImport())->read($path);
            $this->fail('Expected ExcelImportException');
        } catch (ExcelImportException $e) {
            $this->assertStringContainsString('Excel read failed', $e->getMessage());
            $this->assertInstanceOf(\Vtiful\Kernel\Exception::class, $e->getPrevious());
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * cells 以 0 基行/列索引精确写单元格；dates 用于写入真实日期单元格。
     *
     * @param list<array{
     *     name: string,
     *     cells?: array<int, array<int, mixed>>,
     *     dates?: list<array{0: int, 1: int, 2: int, 3?: string}>
     * }> $sheets
     */
    private function createWorkbook(string $fileName, array $sheets): string
    {
        $excel = new Excel(['path' => $this->directory]);

        foreach ($sheets as $index => $sheet) {
            if ($index === 0) {
                $excel->fileName($fileName, $sheet['name']);
            } else {
                $excel->addSheet($sheet['name']);
            }

            foreach ($sheet['cells'] ?? [] as $rowIndex => $cells) {
                foreach ($cells as $columnIndex => $value) {
                    $excel->insertText((int) $rowIndex, $columnIndex, $value);
                }
            }

            foreach ($sheet['dates'] ?? [] as $date) {
                $excel->insertDate($date[0], $date[1], $date[2], $date[3] ?? 'yyyy-mm-dd hh:mm:ss');
            }
        }

        $path = $excel->output();
        $excel->close();

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}

/**
 * 可配置测试夹具：用属性/闭包覆盖声明式钩子与生命周期钩子。
 */
final class ConfigurableExcelImport extends AbstractExcelImport
{
    /** @var list<ExcelImportRow> */
    public array $rows = [];

    /** @var list<int> */
    public array $afterCounts = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private readonly array $options = [])
    {
    }

    protected function handleRow(ExcelImportRow $row): void
    {
        if (isset($this->options['handleRow'])) {
            ($this->options['handleRow'])($row);

            return;
        }

        $this->rows[] = $row;
    }

    protected function sheetName(): ?string
    {
        return $this->options['sheetName'] ?? null;
    }

    protected function headerRow(): int
    {
        return $this->options['headerRow'] ?? 1;
    }

    protected function dataStartRow(): int
    {
        return $this->options['dataStartRow'] ?? 0;
    }

    protected function expectedHeaders(): ?array
    {
        return $this->options['expectedHeaders'] ?? null;
    }

    protected function useStringType(): bool
    {
        return $this->options['useStringType'] ?? true;
    }

    protected function cellTypes(): array
    {
        return $this->options['cellTypes'] ?? [];
    }

    protected function skipEmptyRows(): bool
    {
        return $this->options['skipEmptyRows'] ?? true;
    }

    protected function normalizeCells(array $cells, int $rowNumber): array
    {
        $hook = $this->options['normalizeCells'] ?? null;

        return $hook === null ? $cells : $hook($cells, $rowNumber);
    }

    protected function afterImport(int $rowCount): void
    {
        $this->afterCounts[] = $rowCount;

        if (isset($this->options['afterImport'])) {
            ($this->options['afterImport'])($rowCount);
        }
    }
}

/**
 * 只读夹具：不实现 handleRow()，验证 read() 可独立使用。
 */
final class ReadOnlyExcelImport extends AbstractExcelImport
{
}
