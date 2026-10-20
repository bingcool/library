<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Exception;

/**
 * 配置值非法（如未知 `driver`、未知格式协商器参数）。
 *
 * 与 {@see InvalidImageException} 不同：该异常表示部署配置写错，而不是单张图片内容有问题。
 */
final class InvalidConfigException extends ImageException
{
}
