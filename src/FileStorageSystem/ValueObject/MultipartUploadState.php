<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\ValueObject;

/**
 * 客户端侧分片上传会话状态（uploadId + 已上传 part 列表）。
 */
final class MultipartUploadState
{
    /** @var list<UploadedPart> */
    private array $parts = [];

    /**
     * @param string $uploadId 云厂商返回的会话 ID
     * @param string $path 对象 key（已规范化）
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly string $path,
    ) {
    }

    /** 记录一片上传结果。 */
    public function addPart(UploadedPart $part): void
    {
        $this->parts[] = $part;
    }

    /** @return list<UploadedPart> */
    public function parts(): array
    {
        return $this->parts;
    }

    /**
     * 供 FileDisk::completeMultipartUpload 使用的结构。
     *
     * @return list<array{partNumber: int, etag: string}>
     */
    public function partsAsArray(): array
    {
        return array_map(static fn (UploadedPart $p) => $p->toArray(), $this->parts);
    }
}
