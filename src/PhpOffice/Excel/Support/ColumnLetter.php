<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\Support;

use InvalidArgumentException;
use OutOfRangeException;

/**
 * Excel 列序号与列字母互转，序号从 1 开始（1=A，16,384=XFD）。
 */
final class ColumnLetter
{
    public const MAX_INDEX = 16384;

    public static function fromIndex(int $index): string
    {
        if ($index < 1 || $index > self::MAX_INDEX) {
            throw new OutOfRangeException(sprintf(
                'Column index must be between 1 and %d (%d given)',
                self::MAX_INDEX,
                $index
            ));
        }

        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(65 + $index % 26) . $letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    public static function toIndex(string $letters): int
    {
        if (preg_match('/^[A-Z]{1,3}$/', $letters) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid column letters [%s]', $letters));
        }

        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        if ($index > self::MAX_INDEX) {
            throw new OutOfRangeException(sprintf(
                'Column [%s] exceeds the Excel limit of %d columns',
                $letters,
                self::MAX_INDEX
            ));
        }

        return $index;
    }
}
