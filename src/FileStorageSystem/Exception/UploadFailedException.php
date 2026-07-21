<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Exception;

/** 上传或写对象失败（云 SDK 非 404/403 等映射为此类）。 */
class UploadFailedException extends FileStorageException
{
}
