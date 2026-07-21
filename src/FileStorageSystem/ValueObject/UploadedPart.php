<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\ValueObject;

/**
 * 已完成的分片描述（partNumber + ETag）。
 */
final readonly class UploadedPart
{
    /**
     * @param int $partNumber 从 1 起
     * @param string $etag 无引号的 ETag
     */
    public function __construct(
        public int $partNumber,
        public string $etag,
    ) {
    }

    /**
     * 转为 completeMultipartUpload 所需的数组项。
     *
     * @return array{partNumber: int, etag: string}
     */
    public function toArray(): array
    {
        return [
            'partNumber' => $this->partNumber,
            'etag' => $this->etag,
        ];
    }
}
