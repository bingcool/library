<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Exception;

/** 当前驱动不支持 URL / 预签名（如未配置 publicBaseUrl 的 local）。 */
final class ObjectUrlNotSupportedException extends FileStorageException
{
}
