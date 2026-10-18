<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Exception;

/**
 * 请求的输出格式不被当前 driver 支持（例如 gd 下请求 avif）。
 * 异常消息中会列出该 driver 允许的格式列表。
 */
final class UnsupportedFormatException extends ImageException
{
}
