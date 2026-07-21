<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Support;

/**
 * 统一剥离云厂商 ETag 外层的引号，便于 completeMultipart 与本地 md5 对比。
 */
final class EtagNormalizer
{
    /**
     * 去掉 ETag 两侧的双引号或单引号；空值返回 null。
     *
     * @param string|null $etag 原始 ETag（S3/OSS/COS 常带引号）
     * @return string|null 无引号的 ETag 或 null
     */
    public static function normalize(?string $etag): ?string
    {
        if ($etag === null || $etag === '') {
            return null;
        }

        return trim($etag, "\"'");
    }
}
