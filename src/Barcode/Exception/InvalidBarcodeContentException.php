<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode\Exception;

/**
 * 条码载荷不符合制式（空串、位数、字符集等），或 picqer 编码失败。
 * 属于调用方参数问题，重试不会改变结果。
 */
final class InvalidBarcodeContentException extends BarcodeException
{
}
