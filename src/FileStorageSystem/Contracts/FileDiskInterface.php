<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Contracts;

use Swoolefy\Library\FileStorageSystem\ValueObject\ObjectMetadata;

/**
 * FileDisk 门面契约（宽接口文档化）；Adapter 禁止直接 implements 本接口。
 */
interface FileDiskInterface
{
    /** 写入对象全文；path 由门面 PathNormalizer 处理。 */
    public function putObject(string $path, string $contents, array $options = []): void;

    /** 流式写入。 @param resource $stream */
    public function putObjectStream(string $path, $stream, array $options = []): void;

    /** 读取全文。 */
    public function getObject(string $path): string;

    /** @return resource 可读流 */
    public function getObjectStream(string $path);

    /** 删除单个对象。 */
    public function delete(string $path): void;

    /** 删除目录前缀下所有对象。 */
    public function deleteDirectory(string $path): void;

    /** 对象是否存在。 */
    public function fileExists(string $path): bool;

    /** 目录前缀下是否有对象。 */
    public function directoryExists(string $path): bool;

    /** 复制对象。 */
    public function copy(string $source, string $destination): void;

    /** 移动对象（通常 copy + delete）。 */
    public function move(string $source, string $destination): void;

    /** @return iterable<array{path: string, type: string}> */
    public function listContents(string $path = '', bool $deep = false): iterable;

    /** 获取完整元数据。 */
    public function getMetadata(string $path): ObjectMetadata;

    /** 最后修改 Unix 秒。 */
    public function lastModified(string $path): int;

    /** 与 lastModified 同义。 */
    public function getTimestamp(string $path): int;

    /** 对象字节大小。 */
    public function fileSize(string $path): int;

    /** MIME 类型。 */
    public function mimeType(string $path): string;

    /** 公开或默认样式的对象 URL。 */
    public function getObjectUrl(string $path): string;

    /** 相对秒数预签名。 */
    public function signObjectUrl(string $path, int $timeout, array $options = []): string;

    /** 绝对 expiration 字符串预签名。 */
    public function getTemporaryUrl(string $path, string $expiration, array $options = []): string;

    /** @param resource|string $source 一站式 multipart */
    public function putObjectMultipart(string $path, $source, array $options = []): ObjectMetadata;

    /** 初始化分片，返回 uploadId。 */
    public function createMultipartUpload(string $path, array $options = []): string;

    /** @param resource|string $body 上传单片，返回 ETag */
    public function uploadPart(string $path, string $uploadId, int $partNumber, $body, array $options = []): string;

    /** @param list<array{partNumber: int, etag: string}> $parts */
    public function completeMultipartUpload(string $path, string $uploadId, array $parts, array $options = []): ObjectMetadata;

    /** 中止未完成的分片上传。 */
    public function abortMultipartUpload(string $path, string $uploadId): void;

    /** 底层 SDK 客户端。 */
    public function getClient(): object;

    /** @param class-string $capability 是否支持某 Capability 接口 */
    public function supports(string $capability): bool;
}
