<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Exception;

/**
 * 输入像素超过 `max_pixels`，或编码后字节数超过配置的 `encode.max_bytes`。
 * 解码前用 {@see \Swoolefy\Library\Image\Support\PixelGuard} 拦截，避免 OOM。
 */
final class ImageTooLargeException extends ImageException
{
}
