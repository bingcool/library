<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Contracts;

use Swoolefy\Library\FileStorageSystem\ValueObject\ObjectMetadata;

/**
 * 分片上传能力（含一站式 putObjectMultipart 与会话式 API）。
 */
interface MultipartCapableInterface
{
    /**
     * @param resource|string $source
     * @param array<string, mixed> $options
     */
    public function putObjectMultipart(string $path, $source, array $options = []): ObjectMetadata;

    /**
     * @param array<string, mixed> $options
     */
    public function createMultipartUpload(string $path, array $options = []): string;

    /**
     * @param resource|string $body
     * @param array<string, mixed> $options
     */
    public function uploadPart(
        string $path,
        string $uploadId,
        int $partNumber,
        $body,
        array $options = [],
    ): string;

    /**
     * @param list<array{partNumber: int, etag: string}> $parts
     * @param array<string, mixed> $options
     */
    public function completeMultipartUpload(
        string $path,
        string $uploadId,
        array $parts,
        array $options = [],
    ): ObjectMetadata;

    /** 中止未完成的分片上传。 */
    public function abortMultipartUpload(string $path, string $uploadId): void;
}
