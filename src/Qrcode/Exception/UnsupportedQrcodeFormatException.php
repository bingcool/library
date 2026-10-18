<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\Exception;

/** 请求的输出格式不在 png|svg|eps 支持范围内 */
final class UnsupportedQrcodeFormatException extends QrcodeException
{
}
