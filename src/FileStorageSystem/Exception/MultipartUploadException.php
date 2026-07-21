<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Exception;

/** 分片上传流程失败（含 abort 后包装）。 */
final class MultipartUploadException extends UploadFailedException
{
}
