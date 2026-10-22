<?php

declare(strict_types=1);

namespace Swoolefy\Library\PhpOffice\Excel\Support;

use Swoolefy\Library\PhpOffice\Excel\Exception\ExcelExtensionMissingException;

/**
 * ext-xlswriter 运行期守卫。
 */
final class ExcelExtension
{
    public static function assertLoaded(): void
    {
        if (!extension_loaded('xlswriter') || !class_exists(\Vtiful\Kernel\Excel::class)) {
            throw new ExcelExtensionMissingException(
                'PhpOffice Excel requires ext-xlswriter; install it via `pecl install xlswriter` and enable the extension'
            );
        }
    }
}
