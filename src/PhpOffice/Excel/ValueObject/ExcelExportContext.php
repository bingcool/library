<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\ValueObject;

use Swoolefy\Library\PhpOffice\Excel\Support\ColumnLetter;

/**
 * 导出钩子上下文：描述表格已写到哪，不含可变状态。
 *
 * firstDataRowIndex() / nextRowIndex() 为 0 基，可直接传给 insertText()；
 * nextRowNumber() 为 1 基，用于展示或范围字符串。
 */
final readonly class ExcelExportContext
{
    /**
     * @param list<string> $headers
     * @param int $headerRowCount writeHeader() 返回的表头行数
     * @param int $dataRowCount writeRows() 返回的数据行数，不含表头与合计行
     */
    public function __construct(
        public array $headers,
        public int $headerRowCount,
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

    /** 数据起始行，0 基 */
    public function firstDataRowIndex(): int
    {
        return $this->headerRowCount;
    }

    /** 下一个可写行，0 基 */
    public function nextRowIndex(): int
    {
        return $this->headerRowCount + $this->dataRowCount;
    }

    /** 下一个可写行，1 基 */
    public function nextRowNumber(): int
    {
        return $this->nextRowIndex() + 1;
    }

    /**
     * 默认筛选范围：最后一个表头行到最后一个数据行，不含 afterRows() 追加的合计行。
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
