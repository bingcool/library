<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\ValueObject;

/**
 * 对象元数据（只读值对象）。字段允许 null：表示驱动未提供该信息。
 */
final readonly class ObjectMetadata
{
    /**
     * @param int|null $size 字节大小
     * @param string|null $mime Content-Type
     * @param string|null $etag 去引号后的 ETag
     * @param string|null $checksum 厂商 CRC 等
     * @param int|null $created 创建时间 Unix 秒
     * @param int|null $modified 最后修改 Unix 秒
     */
    public function __construct(
        public ?int $size = null,
        public ?string $mime = null,
        public ?string $etag = null,
        public ?string $checksum = null,
        public ?int $created = null,
        public ?int $modified = null,
    ) {
    }

    /**
     * 从数组构造（键名与 toArray 一致）。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            size: isset($data['size']) ? (int) $data['size'] : null,
            mime: isset($data['mime']) ? (string) $data['mime'] : null,
            etag: isset($data['etag']) ? (string) $data['etag'] : null,
            checksum: isset($data['checksum']) ? (string) $data['checksum'] : null,
            created: isset($data['created']) ? (int) $data['created'] : null,
            modified: isset($data['modified']) ? (int) $data['modified'] : null,
        );
    }

    /**
     * 序列化为关联数组（API 响应用）。
     *
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'size' => $this->size,
            'mime' => $this->mime,
            'etag' => $this->etag,
            'checksum' => $this->checksum,
            'created' => $this->created,
            'modified' => $this->modified,
        ];
    }
}
