<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode\Exception;

/**
 * PNG 输出需要 ext-gd 或 ext-imagick 至少其一，当前环境均不可用。
 */
final class MissingExtensionException extends BarcodeException
{
}
