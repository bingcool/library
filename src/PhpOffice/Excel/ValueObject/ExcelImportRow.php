<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\ValueObject;

/**
 * 一行已读取并归一化的 Excel 数据。
 *
 * rowNumber 是 Excel 界面行号（1 基）；cells 是 0 基列表；values 以表头文本为键。
 */
final readonly class ExcelImportRow
{
    /**
     * @param list<mixed> $cells
     * @param array<array-key, mixed> $values
     */
    public function __construct(
        public int $rowNumber,
        public array $cells,
        public array $values,
    ) {
    }

    public function cell(int $index, mixed $default = null): mixed
    {
        return array_key_exists($index, $this->cells) ? $this->cells[$index] : $default;
    }

    public function value(string $header, mixed $default = null): mixed
    {
        return array_key_exists($header, $this->values) ? $this->values[$header] : $default;
    }

    /** 所有单元格为 null 或空/空白字符串时视为空行 */
    public function isEmpty(): bool
    {
        foreach ($this->cells as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return false;
        }

        return true;
    }
}
