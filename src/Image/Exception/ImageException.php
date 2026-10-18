<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Exception;

/**
 * Image 模块异常基类。业务可 `catch (ImageException)` 统一收口。
 */
class ImageException extends \RuntimeException
{
}
