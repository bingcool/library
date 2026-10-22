<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\PhpOffice\Excel;

use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;
use Swoolefy\Library\PhpOffice\Excel\AbstractExcelExport;
use Swoolefy\Library\PhpOffice\Excel\Exception\ExcelExportException;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelExportException;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelRowException;
use Swoolefy\Library\PhpOffice\Excel\Support\ColumnLetter;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelExportContext;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelExportResult;
use Vtiful\Kernel\Excel;
use Vtiful\Kernel\Format;
use ZipArchive;

/**
 * PhpOffice Excel 导出回归测试（23 组）。
 *
 * 需要 ext-xlswriter 与 ext-zip；所有文件写入 sys_get_temp_dir() 下的唯一目录，tearDown 清理。
 */
final class ExcelExportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!extension_loaded('xlswriter') || !class_exists(Excel::class)) {
            $this->markTestSkipped('ext-xlswriter required');
        }

        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip required');
        }

        $this->directory = sys_get_temp_dir() . '/library-excel-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            $this->removeDirectory($this->directory);
        }
    }

    // ------------------------------------------------------------------
    // 1. 基本导出
    // ------------------------------------------------------------------

    public function testBasicExport(): void
    {
        $result = $this->export(
            ['编号', '名称', '金额'],
            [
                ['A001', '苹果', 12.5],
                ['A002', '香蕉', 3],
                ['A003', '橙子', 8.5],
            ],
            ['fileName' => 'basic', 'autoFilter' => true]
        );

        $this->assertSame('basic.xlsx', $result->fileName);
        $this->assertSame('Sheet1', $result->sheetName);
        $this->assertSame(3, $result->rowCount);
        $this->assertGreaterThan(0, $result->size);
        $this->assertSame(ExcelExportResult::MIME, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertSame(realpath($result->path), $result->path);
        $this->assertFileExists($result->path);

        $rows = $this->readRows($result);
        $this->assertCount(4, $rows);
        $this->assertSame(['编号', '名称', '金额'], $rows[0]);
        $this->assertSame(['A001', '苹果', 12.5], $rows[1]);
        $this->assertSame(['A003', '橙子', 8.5], $rows[3]);
        $this->assertStringContainsString('<autoFilter ref="A1:C4"/>', $this->sheetXml($result->path));
    }

    // ------------------------------------------------------------------
    // 2. 生成器跨批次
    // ------------------------------------------------------------------

    public function testGeneratorRowsAcrossBatches(): void
    {
        $rows = (static function (): \Generator {
            for ($i = 1; $i <= 2501; $i++) {
                yield ['NO-' . $i, $i];
            }
        })();

        $result = $this->export(['编号', '序号'], $rows, [
            'fileName' => 'batch',
            'batchSize' => 1000,
            'autoFilter' => false,
        ]);

        $this->assertSame(2501, $result->rowCount);

        $data = $this->readRows($result);
        $this->assertCount(2502, $data);
        $this->assertSame(['NO-1', 1], $data[1]);
        $this->assertSame(['NO-1000', 1000], $data[1000]);
        $this->assertSame(['NO-1001', 1001], $data[1001]);
        $this->assertSame(['NO-2501', 2501], $data[2501]);
    }

    // ------------------------------------------------------------------
    // 3. constMemory 开/关
    // ------------------------------------------------------------------

    public function testConstMemoryModes(): void
    {
        foreach ([true, false] as $constMemory) {
            $fileName = $constMemory ? 'const_memory' : 'normal_memory';
            $result = $this->export(['编号', '名称'], [['00123', '文本']], [
                'fileName' => $fileName,
                'useConstMemory' => $constMemory,
                'autoFilter' => false,
            ]);

            // getSheetData() 会把 "00123" 读成 int 123，文本类型必须看 XML
            $this->assertSame('文本', $this->readRows($result)[1][1]);
            $xml = $this->sheetXml($result->path);

            if ($constMemory) {
                $this->assertStringContainsString('t="inlineStr"', $this->cellOpeningTag($xml, 'A2'));
                $this->assertStringContainsString('<t>00123</t>', $this->cellXml($xml, 'A2'));
            } else {
                $this->assertStringContainsString('t="s"', $this->cellOpeningTag($xml, 'A2'));
                $this->assertStringContainsString('00123', $this->sharedStringsXml($result->path));
            }
        }

        $this->assertCount(2, array_diff(scandir($this->directory) ?: [], ['.', '..']));
    }

    // ------------------------------------------------------------------
    // 4. 中文文件名 / sheet 名
    // ------------------------------------------------------------------

    public function testChineseFileNameAndSheetName(): void
    {
        $result = $this->export(['列一', '列二'], [['值', 2]], [
            'fileName' => '订单导出_测试',
            'sheetName' => '订单明细',
            'autoFilter' => false,
        ]);

        $this->assertSame('订单导出_测试.xlsx', $result->fileName);
        $this->assertSame('订单明细', $result->sheetName);
        $this->assertFileExists($result->path);
        $this->assertSame(['列一', '列二'], $this->readRows($result)[0]);
    }

    // ------------------------------------------------------------------
    // 5. 单元格类型归一化
    // ------------------------------------------------------------------

    public function testCellTypeNormalization(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        $date = new \DateTimeImmutable('2026-10-22 09:30:00');

        $result = $this->export(
            ['null', 'bool', 'int', 'float', 'text', 'date', 'stringable'],
            [[null, true, 42, 3.25, 'hello', $date, $stringable]],
            ['fileName' => 'types', 'autoFilter' => false]
        );

        $rows = $this->readRows($result);
        $this->assertSame('', $rows[1][0]);
        $this->assertSame(1, $rows[1][1]);
        $this->assertSame(42, $rows[1][2]);
        $this->assertSame(3.25, $rows[1][3]);
        $this->assertSame('hello', $rows[1][4]);
        $this->assertSame('2026-10-22 09:30:00', $rows[1][5]);
        $this->assertSame('stringable-value', $rows[1][6]);

        $xml = $this->sheetXml($result->path);
        $this->assertNull($this->cellXml($xml, 'A2'));
        $this->assertStringContainsString('t="b"', $this->cellOpeningTag($xml, 'B2'));
        $this->assertStringNotContainsString('t="', $this->cellOpeningTag($xml, 'C2'));
        $this->assertStringNotContainsString('t="', $this->cellOpeningTag($xml, 'D2'));
        $this->assertStringContainsString('t="inlineStr"', $this->cellOpeningTag($xml, 'E2'));
        $this->assertStringContainsString('2026-10-22 09:30:00', $this->cellXml($xml, 'F2'));
        $this->assertStringContainsString('stringable-value', $this->cellXml($xml, 'G2'));
    }

    // ------------------------------------------------------------------
    // 6. 数字字符串 / 公式样式字符串保持文本
    // ------------------------------------------------------------------

    public function testNumericAndFormulaLikeStringsStayText(): void
    {
        $result = $this->export(
            ['编号', '公式', '手机号', '负数'],
            [['00123', '=SUM(A1:A2)', '+8613800138000', '-1']],
            ['fileName' => 'text_cells', 'autoFilter' => false]
        );

        $xml = $this->sheetXml($result->path);
        foreach (['A2', 'B2', 'C2', 'D2'] as $cell) {
            $this->assertStringContainsString('t="inlineStr"', $this->cellOpeningTag($xml, $cell));
        }

        $this->assertStringContainsString('<t>00123</t>', $this->cellXml($xml, 'A2'));
        $this->assertStringContainsString('<t>=SUM(A1:A2)</t>', $this->cellXml($xml, 'B2'));
        $this->assertStringNotContainsString('<f>', $xml);
    }

    // ------------------------------------------------------------------
    // 7. 表头为空 / 列数超限
    // ------------------------------------------------------------------

    public function testInvalidHeadersAreRejected(): void
    {
        foreach ([[], ['ok', '  '], array_fill(0, 16385, 'h')] as $headers) {
            try {
                $this->export($headers, [[1]], ['fileName' => 'bad_header']);
                $this->fail('Expected InvalidExcelExportException');
            } catch (InvalidExcelExportException $e) {
                $this->assertStringContainsString('header', strtolower($e->getMessage()));
            }
        }

        $this->assertNoFiles();
    }

    // ------------------------------------------------------------------
    // 8. sheet 名非法
    // ------------------------------------------------------------------

    #[DataProvider('invalidSheetNameProvider')]
    public function testInvalidSheetNameIsRejected(string $sheetName): void
    {
        try {
            $this->export(['a'], [[1]], ['fileName' => 'bad_sheet', 'sheetName' => $sheetName]);
            $this->fail('Expected InvalidExcelExportException');
        } catch (InvalidExcelExportException $e) {
            $this->assertStringContainsString('sheet name', strtolower($e->getMessage()));
        }

        $this->assertNoFiles();
    }

    public static function invalidSheetNameProvider(): array
    {
        return [
            'slash' => ['bad/name'],
            'backslash' => ['bad\\name'],
            'bracket' => ['bad[name'],
            'colon' => ['bad:name'],
            'star' => ['bad*name'],
            'question' => ['bad?name'],
            'too long' => [str_repeat('a', 32)],
            'leading apostrophe' => ["'quoted"],
            'trailing apostrophe' => ["quoted'"],
        ];
    }

    // ------------------------------------------------------------------
    // 9. 文件名非法
    // ------------------------------------------------------------------

    #[DataProvider('invalidFileNameProvider')]
    public function testInvalidFileNameIsRejected(string $fileNamePrefix): void
    {
        $token = bin2hex(random_bytes(4));
        $fileName = in_array($fileNamePrefix, ['.', '..', '   '], true)
            ? $fileNamePrefix
            : $fileNamePrefix . $token;

        try {
            $this->export(['a'], [[1]], ['fileName' => $fileName]);
            $this->fail('Expected InvalidExcelExportException');
        } catch (InvalidExcelExportException $e) {
            $this->assertStringContainsString('file name', strtolower($e->getMessage()));
        }

        $this->assertNoFiles();

        if (str_starts_with($fileName, '../')) {
            $this->assertFileDoesNotExist(dirname($this->directory) . '/' . substr($fileName, 3) . '.xlsx');
        }
    }

    public static function invalidFileNameProvider(): array
    {
        return [
            'traversal' => ['../escape_'],
            'slash' => ['sub/dir_'],
            'backslash' => ['sub\\dir_'],
            'control' => ["bad\x01name_"],
            'too long' => [str_repeat('a', 121)],
            'dot' => ['.'],
            'dot dot' => ['..'],
            'blank' => ['   '],
        ];
    }

    // ------------------------------------------------------------------
    // 10. 目录非法
    // ------------------------------------------------------------------

    public function testInvalidDirectoryIsRejected(): void
    {
        try {
            (new ConfigurableExcelExport(['a'], [[1]], ['fileName' => 'missing_dir']))
                ->export($this->directory . '/missing');
            $this->fail('Expected InvalidExcelExportException');
        } catch (InvalidExcelExportException $e) {
            $this->assertStringContainsString('directory', strtolower($e->getMessage()));
        }

        $filePath = $this->directory . '/plain.txt';
        file_put_contents($filePath, 'x');

        try {
            (new ConfigurableExcelExport(['a'], [[1]], ['fileName' => 'not_a_dir']))
                ->export($filePath);
            $this->fail('Expected InvalidExcelExportException');
        } catch (InvalidExcelExportException $e) {
            $this->assertStringContainsString('directory', strtolower($e->getMessage()));
        }
        unlink($filePath);

        if (is_dir('/sys') && !is_writable('/sys')) {
            try {
                (new ConfigurableExcelExport(['a'], [[1]], ['fileName' => 'readonly_dir']))
                    ->export('/sys');
                $this->fail('Expected InvalidExcelExportException');
            } catch (InvalidExcelExportException $e) {
                $this->assertStringContainsString('not writable', strtolower($e->getMessage()));
            }
        }

        $this->assertNoFiles();
    }

    // ------------------------------------------------------------------
    // 11. 目标文件已存在
    // ------------------------------------------------------------------

    public function testExistingTargetFileIsNeverOverwritten(): void
    {
        $path = $this->directory . '/taken.xlsx';
        file_put_contents($path, 'KEEP');

        try {
            $this->export(['a'], [[1]], ['fileName' => 'taken']);
            $this->fail('Expected InvalidExcelExportException');
        } catch (InvalidExcelExportException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->assertSame('KEEP', file_get_contents($path));
    }

    // ------------------------------------------------------------------
    // 12. 行数据非法
    // ------------------------------------------------------------------

    #[DataProvider('invalidRowProvider')]
    public function testInvalidRowIsRejected(array $row, int $rowNumber, int $columnIndex, ?string $columnLetter): void
    {
        try {
            $this->export(['a', 'b'], [$row], ['fileName' => 'bad_row']);
            $this->fail('Expected InvalidExcelRowException');
        } catch (InvalidExcelRowException $e) {
            $this->assertSame($rowNumber, $e->rowNumber);
            $this->assertSame($columnIndex, $e->columnIndex);
            $this->assertSame($columnLetter, $e->columnLetter);
        }

        $this->assertNoFiles();
    }

    public static function invalidRowProvider(): array
    {
        return [
            'non-list' => [['x' => 1, 'y' => 2], 1, 0, null],
            'width mismatch' => [[1], 1, 0, null],
            'unsupported type' => [[1, ['nested']], 1, 2, 'B'],
            'too long cell' => [[str_repeat('x', 32768), 2], 1, 1, 'A'],
        ];
    }

    // ------------------------------------------------------------------
    // 13. 业务异常透传且清理文件
    // ------------------------------------------------------------------

    public function testBusinessExceptionPropagatesAndCleansFile(): void
    {
        $rows = (static function (): \Generator {
            yield ['a', 1];
            throw new RuntimeException('rows boom');
        })();

        try {
            $this->export(['a', 'b'], $rows, ['fileName' => 'rows_throw']);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('rows boom', $e->getMessage());
        }
        $this->assertNoFiles();

        try {
            $this->export(['a', 'b'], [['a', 1]], [
                'fileName' => 'hook_throw',
                'afterRows' => static function (Excel $excel, ExcelExportContext $context): void {
                    throw new DomainException('hook boom');
                },
            ]);
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertSame('hook boom', $e->getMessage());
        }
        $this->assertNoFiles();
    }

    // ------------------------------------------------------------------
    // 14. 实例二次导出
    // ------------------------------------------------------------------

    public function testExportInstanceCannotBeReused(): void
    {
        $export = new ConfigurableExcelExport(['a'], [[1]], ['fileName' => 'once', 'autoFilter' => false]);
        $result = $export->export($this->directory);

        try {
            $export->export($this->directory);
            $this->fail('Expected ExcelExportException');
        } catch (ExcelExportException $e) {
            $this->assertStringContainsString('already been exported', $e->getMessage());
        }

        $this->assertFileExists($result->path);
    }

    // ------------------------------------------------------------------
    // 15. ColumnLetter
    // ------------------------------------------------------------------

    public function testColumnLetterConversion(): void
    {
        $this->assertSame('A', ColumnLetter::fromIndex(1));
        $this->assertSame('Z', ColumnLetter::fromIndex(26));
        $this->assertSame('AA', ColumnLetter::fromIndex(27));
        $this->assertSame('AZ', ColumnLetter::fromIndex(52));
        $this->assertSame('BA', ColumnLetter::fromIndex(53));
        $this->assertSame('XFD', ColumnLetter::fromIndex(16384));
        $this->assertSame(1, ColumnLetter::toIndex('A'));
        $this->assertSame(16384, ColumnLetter::toIndex('XFD'));

        foreach ([0, 16385] as $invalid) {
            try {
                ColumnLetter::fromIndex($invalid);
                $this->fail('Expected OutOfRangeException');
            } catch (\OutOfRangeException) {
            }
        }
    }

    // ------------------------------------------------------------------
    // 16. 列宽 / 冻结 / 筛选
    // ------------------------------------------------------------------

    public function testColumnWidthsFreezePanesAndAutoFilter(): void
    {
        $result = $this->export(['a', 'b', 'c'], [[1, 2, 3], [4, 5, 6]], [
            'fileName' => 'layout',
            'columnWidths' => ['A' => 24, 'B:C' => 18],
            'freezeRowCount' => 1,
            'autoFilter' => true,
        ]);

        $xml = $this->sheetXml($result->path);
        $this->assertMatchesRegularExpression('/<col min="1" max="1" width="2[45]\.[0-9]+"/', $xml);
        $this->assertMatchesRegularExpression('/<col min="2" max="3" width="18\.[0-9]+"/', $xml);
        $this->assertStringContainsString('<pane ySplit="1"', $xml);
        $this->assertStringContainsString('<autoFilter ref="A1:C3"/>', $xml);
    }

    // ------------------------------------------------------------------
    // 17. 多行表头 + 合并说明行
    // ------------------------------------------------------------------

    public function testMultiRowHeaderWithMergedNote(): void
    {
        $result = $this->export(['订单号', '金额', '状态', '时间'], [
            ['A1', 10, 'ok', '2026-10-22'],
            ['A2', 20, 'ok', '2026-10-22'],
        ], [
            'fileName' => 'multi_header',
            'freezeRowCount' => 2,
            'autoFilter' => false,
            'writeHeader' => static function (Excel $excel, array $headers, $headerFormat): int {
                $excel->header($headers, $headerFormat);
                $note = new Format($excel->getHandle());
                $note->italic()->align(Format::FORMAT_ALIGN_CENTER);
                $excel->mergeCells('A2:D2', '金额单位：元', $note->toResource());

                return 2;
            },
        ]);

        $this->assertSame(2, $result->rowCount);

        $xml = $this->sheetXml($result->path);
        $this->assertStringContainsString('<mergeCell ref="A2:D2"/>', $xml);
        $this->assertStringContainsString('<pane ySplit="2"', $xml);
        $this->assertStringNotContainsString('<autoFilter', $xml);

        $rows = $this->readRows($result);
        $this->assertSame('金额单位：元', $rows[1][0]);
        $this->assertSame(['A1', 10, 'ok', '2026-10-22'], $rows[2]);
        $this->assertSame(['A2', 20, 'ok', '2026-10-22'], $rows[3]);
    }

    // ------------------------------------------------------------------
    // 18. afterRows 合并数据 + 合计行（普通模式）
    // ------------------------------------------------------------------

    public function testAfterRowsMergeAndTotalRow(): void
    {
        $result = $this->export(['组', '值'], [['G1', 1], ['G1', 2], ['G2', 3]], [
            'fileName' => 'grouped',
            'useConstMemory' => false,
            'autoFilter' => false,
            'afterRows' => static function (Excel $excel, ExcelExportContext $context): void {
                $excel->mergeCells('A2:A3', 'G1');
                $totalRow = $context->nextRowIndex();
                $excel->insertText($totalRow, 0, '合计');
                $excel->insertText($totalRow, 1, 6);
            },
        ]);

        $this->assertSame(3, $result->rowCount);

        $xml = $this->sheetXml($result->path);
        $this->assertStringContainsString('<mergeCell ref="A2:A3"/>', $xml);
        $this->assertStringNotContainsString('<autoFilter', $xml);

        $rows = $this->readRows($result);
        $this->assertCount(5, $rows);
        $this->assertSame(['合计', 6], $rows[4]);
    }

    // ------------------------------------------------------------------
    // 18.1 合计行 + 筛选：普通模式可排除合计行，constMemory 必须覆盖最后一行
    // ------------------------------------------------------------------

    public function testTotalRowAutoFilterRangeInBothModes(): void
    {
        $normal = $this->export(['组', '值'], [['G1', 1], ['G1', 2], ['G2', 3]], [
            'fileName' => 'total_filter_normal',
            'useConstMemory' => false,
            'autoFilter' => true,
            'autoFilterRange' => 'A1:B4',
            'afterRows' => static function (Excel $excel, ExcelExportContext $context): void {
                $totalRow = $context->nextRowIndex();
                $excel->insertText($totalRow, 0, '合计');
                $excel->insertText($totalRow, 1, 6);
            },
        ]);

        $this->assertStringContainsString('<autoFilter ref="A1:B4"/>', $this->sheetXml($normal->path));
        $this->assertSame(['合计', 6], $this->readRows($normal)[4]);

        $const = $this->export(['组', '值'], [['G1', 1]], [
            'fileName' => 'total_filter_const',
            'autoFilter' => true,
            'autoFilterRange' => 'A1:B3',
            'afterRows' => static function (Excel $excel, ExcelExportContext $context): void {
                $totalRow = $context->nextRowIndex();
                $excel->insertText($totalRow, 0, '合计');
                $excel->insertText($totalRow, 1, 6);
            },
        ]);

        $this->assertStringContainsString('<autoFilter ref="A1:B3"/>', $this->sheetXml($const->path));
        $this->assertSame(['合计', 6], $this->readRows($const)[2]);
    }

    // ------------------------------------------------------------------
    // 19. 完全自定义表头与写行
    // ------------------------------------------------------------------

    public function testFullyCustomHeaderAndRows(): void
    {
        $result = $this->export(['项目', '数量', '金额'], [
            ['项目A', 1, 10],
            ['项目B', 2, 20],
        ], [
            'fileName' => 'custom_layout',
            'freezeRowCount' => 2,
            'autoFilter' => false,
            'writeHeader' => static function (Excel $excel, array $headers, $headerFormat): int {
                $title = new Format($excel->getHandle());
                $title->bold()->fontSize(16)->align(Format::FORMAT_ALIGN_CENTER);
                $excel->mergeCells('A1:C1', '2026 年销售报表', $title->toResource());

                foreach ($headers as $index => $header) {
                    $excel->insertText(1, $index, $header);
                }

                return 2;
            },
            'writeRows' => static function (Excel $excel, iterable $rows, array $headers): int {
                $startRow = $excel->getCurrentLine();
                $count = 0;

                foreach ($rows as $row) {
                    foreach ($row as $column => $value) {
                        $excel->insertText($startRow + $count, $column, $value);
                    }
                    $count++;
                }

                return $count;
            },
        ]);

        $this->assertSame(2, $result->rowCount);

        $xml = $this->sheetXml($result->path);
        $this->assertStringContainsString('<mergeCell ref="A1:C1"/>', $xml);
        $this->assertStringContainsString('<pane ySplit="2"', $xml);

        $rows = $this->readRows($result);
        $this->assertSame('2026 年销售报表', $rows[0][0]);
        $this->assertSame(['项目', '数量', '金额'], $rows[1]);
        $this->assertSame(['项目A', 1, 10], $rows[2]);
        $this->assertSame(['项目B', 2, 20], $rows[3]);
    }

    // ------------------------------------------------------------------
    // 20. 自定义筛选范围 / 返回 null
    // ------------------------------------------------------------------

    public function testCustomAutoFilterRangeAndNull(): void
    {
        $custom = $this->export(['a', 'b'], [['x', 1]], [
            'fileName' => 'filter_custom',
            'autoFilter' => true,
            'autoFilterRange' => 'A1:B2',
        ]);
        $this->assertStringContainsString(
            '<autoFilter ref="A1:B2"/>',
            $this->sheetXml($custom->path)
        );

        $none = $this->export(['a', 'b'], [['x', 1]], [
            'fileName' => 'filter_none',
            'autoFilter' => true,
            'autoFilterRange' => null,
        ]);
        $this->assertStringNotContainsString('<autoFilter', $this->sheetXml($none->path));
    }

    // ------------------------------------------------------------------
    // 21. 自定义表头样式
    // ------------------------------------------------------------------

    public function testCustomHeaderFormat(): void
    {
        $result = $this->export(['a', 'b'], [[1, 2]], [
            'fileName' => 'styled_header',
            'autoFilter' => false,
            'configureHeaderFormat' => static function (Format $format): void {
                $format->fontSize(14)->fontColor(0xFF0000);
            },
        ]);

        $styles = $this->stylesXml($result->path);
        $this->assertStringContainsString('<sz val="14"/>', $styles);
        $this->assertStringContainsString('<color rgb="FFFF0000"/>', $styles);
        $this->assertStringContainsString('<b/>', $styles);

        $this->assertSame(['a', 'b'], $this->readRows($result)[0]);
        $this->assertSame(1, $result->rowCount);
    }

    // ------------------------------------------------------------------
    // 22. 钩子返回值非法
    // ------------------------------------------------------------------

    #[DataProvider('invalidHookReturnProvider')]
    public function testInvalidHookReturnValuesAreRejected(array $options, string $message): void
    {
        try {
            $this->export(['a', 'b'], [['x', 1]], $options);
            $this->fail('Expected InvalidExcelExportException');
        } catch (InvalidExcelExportException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertNoFiles();
    }

    public static function invalidHookReturnProvider(): array
    {
        return [
            'writeHeader -1' => [
                [
                    'fileName' => 'hook_header_neg',
                    'writeHeader' => static function (Excel $excel, array $headers, $headerFormat): int {
                        $excel->header($headers, $headerFormat);

                        return -1;
                    },
                ],
                'writeHeader()',
            ],
            'writeHeader 101' => [
                [
                    'fileName' => 'hook_header_over',
                    'writeHeader' => static fn (Excel $excel, array $headers, $headerFormat): int => 101,
                ],
                'writeHeader()',
            ],
            'writeRows -1' => [
                [
                    'fileName' => 'hook_rows_neg',
                    'writeRows' => static fn (Excel $excel, iterable $rows, array $headers): int => -1,
                ],
                'writeRows()',
            ],
            'writeRows over limit' => [
                [
                    'fileName' => 'hook_rows_over',
                    'writeRows' => static fn (Excel $excel, iterable $rows, array $headers): int => 1048576,
                ],
                'writeRows()',
            ],
            'freeze over header' => [
                [
                    'fileName' => 'hook_freeze',
                    'freezeRowCount' => 3,
                    'writeHeader' => static fn (Excel $excel, array $headers, $headerFormat): int => 2,
                ],
                'freezeRowCount()',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function export(array $headers, iterable $rows, array $options = []): ExcelExportResult
    {
        return (new ConfigurableExcelExport($headers, $rows, $options))->export($this->directory);
    }

    /**
     * @return list<list<mixed>>
     */
    private function readRows(ExcelExportResult $result): array
    {
        $excel = (new Excel(['path' => $this->directory]))
            ->openFile($result->fileName)
            ->openSheet($result->sheetName);
        $data = $excel->getSheetData();
        $excel->close();

        return $data;
    }

    private function sheetXml(string $path): string
    {
        return $this->zipEntry($path, 'xl/worksheets/sheet1.xml');
    }

    private function stylesXml(string $path): string
    {
        return $this->zipEntry($path, 'xl/styles.xml');
    }

    private function sharedStringsXml(string $path): string
    {
        return $this->zipEntry($path, 'xl/sharedStrings.xml');
    }

    private function zipEntry(string $path, string $entry): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Cannot open xlsx archive');
        $content = $zip->getFromName($entry);
        $zip->close();

        $this->assertIsString($content, sprintf('Missing xlsx entry %s', $entry));

        return $content;
    }

    private function cellOpeningTag(string $xml, string $reference): string
    {
        $matched = preg_match('/<c r="' . preg_quote($reference, '/') . '"[^>]*>/', $xml, $matches);
        $this->assertSame(1, $matched, sprintf('Cell %s not found', $reference));

        return $matches[0];
    }

    private function cellXml(string $xml, string $reference): ?string
    {
        $matched = preg_match('/<c r="' . preg_quote($reference, '/') . '".*?<\/c>/s', $xml, $matches);

        return $matched === 1 ? $matches[0] : null;
    }

    private function assertNoFiles(): void
    {
        $files = array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));
        $this->assertSame([], $files, 'Export failure left files behind');
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
final class ConfigurableExcelExport extends AbstractExcelExport
{
    /**
     * @param list<string|int|float> $headers
     * @param iterable<array<mixed>> $rows
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly array $headers,
        private readonly iterable $rows,
        private readonly array $options = [],
    ) {
    }

    protected function headers(): array
    {
        return $this->headers;
    }

    protected function rows(): iterable
    {
        yield from $this->rows;
    }

    protected function fileName(): string
    {
        return $this->options['fileName'] ?? 'export_' . bin2hex(random_bytes(4));
    }

    protected function sheetName(): string
    {
        return $this->options['sheetName'] ?? 'Sheet1';
    }

    protected function columnWidths(): array
    {
        return $this->options['columnWidths'] ?? [];
    }

    protected function freezeRowCount(): int
    {
        return $this->options['freezeRowCount'] ?? 1;
    }

    protected function autoFilter(): bool
    {
        return $this->options['autoFilter'] ?? true;
    }

    protected function useConstMemory(): bool
    {
        return $this->options['useConstMemory'] ?? true;
    }

    protected function batchSize(): int
    {
        return $this->options['batchSize'] ?? 1000;
    }

    protected function dateFormat(): string
    {
        return $this->options['dateFormat'] ?? 'Y-m-d H:i:s';
    }

    protected function configureSheet(Excel $excel): void
    {
        if (isset($this->options['configureSheet'])) {
            ($this->options['configureSheet'])($excel);
        }
    }

    protected function configureHeaderFormat(Format $format): void
    {
        parent::configureHeaderFormat($format);

        if (isset($this->options['configureHeaderFormat'])) {
            ($this->options['configureHeaderFormat'])($format);
        }
    }

    protected function writeHeader(Excel $excel, array $headers, $headerFormat): int
    {
        if (isset($this->options['writeHeader'])) {
            return ($this->options['writeHeader'])($excel, $headers, $headerFormat);
        }

        return parent::writeHeader($excel, $headers, $headerFormat);
    }

    protected function writeRows(Excel $excel, iterable $rows, array $headers): int
    {
        if (isset($this->options['writeRows'])) {
            return ($this->options['writeRows'])($excel, $rows, $headers);
        }

        return parent::writeRows($excel, $rows, $headers);
    }

    protected function afterRows(Excel $excel, ExcelExportContext $context): void
    {
        if (isset($this->options['afterRows'])) {
            ($this->options['afterRows'])($excel, $context);
        }
    }

    protected function autoFilterRange(ExcelExportContext $context): ?string
    {
        if (array_key_exists('autoFilterRange', $this->options)) {
            return $this->options['autoFilterRange'];
        }

        return parent::autoFilterRange($context);
    }

    protected function beforeOutput(Excel $excel, ExcelExportContext $context): void
    {
        if (isset($this->options['beforeOutput'])) {
            ($this->options['beforeOutput'])($excel, $context);
        }
    }
}
