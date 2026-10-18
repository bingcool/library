<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode\Exception;

/**
 * 不支持的 {@see \Swoolefy\Library\Barcode\BarcodeType} 或 base64 的 format 字符串。
 * 属于配置/调用方错误。
 */
final class UnsupportedBarcodeTypeException extends BarcodeException
{
}
