<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Contracts;

/**
 * 暴露底层云 SDK 客户端（高级定制场景）。
 */
interface CloudClientAwareInterface
{
    /** @return object 如 S3Client、OSS Client、COS Client */
    public function getClient(): object;
}
