<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Exception;

/**
 * 输入无法解码、画布尺寸非法、水印路径非法、预设名不存在等业务/参数错误。
 * 与 {@see MissingExtensionException} 不同：这是调用方传入的内容问题，不是环境缺扩展。
 */
final class InvalidImageException extends ImageException
{
}
