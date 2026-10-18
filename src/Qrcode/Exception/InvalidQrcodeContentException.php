<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\Exception;

/**
 * 待编码内容非法：空串、过长、WiFi 参数错误、Logo 比例/纠错等级不满足等。
 * 属于调用方参数问题，重试不会改变结果。
 */
final class InvalidQrcodeContentException extends QrcodeException
{
}
