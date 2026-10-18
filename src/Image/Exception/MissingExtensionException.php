<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Exception;

/**
 * 当前配置的 driver 所需 PHP 扩展未安装（如 gd / imagick）。
 * 在首次 {@see \Swoolefy\Library\Image\ImageManager::open()} 或 {@see create()} 构建底层驱动时抛出。
 */
final class MissingExtensionException extends ImageException
{
}
