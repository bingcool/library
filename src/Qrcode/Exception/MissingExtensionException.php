<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\Exception;

/**
 * 当前输出格式所需的 PHP 扩展未安装。
 * PNG 需要 gd；SVG/EPS 需要 xmlwriter。
 */
final class MissingExtensionException extends QrcodeException
{
}
