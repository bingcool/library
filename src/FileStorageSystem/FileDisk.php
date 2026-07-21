<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem;

use Swoolefy\Library\FileStorageSystem\Contracts\CloudClientAwareInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\FileDiskInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\MultipartCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectMetadataCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectStoreCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectUrlCapableInterface;
use Swoolefy\Library\FileStorageSystem\Support\CapabilityGuard;
use Swoolefy\Library\FileStorageSystem\Support\PathNormalizer;
use Swoolefy\Library\FileStorageSystem\ValueObject\ObjectMetadata;

/**
 * Disk 门面：path 经 PathNormalizer 后委托各 Capability；可选能力经 CapabilityGuard 校验。
 */
final class FileDisk implements FileDiskInterface
{
    /**
     * @param ObjectStoreCapableInterface $store 必选：读写删列
     * @param ObjectMetadataCapableInterface|null $metadata 可选元数据能力
     * @param ObjectUrlCapableInterface|null $url 可选 URL / 预签名
     * @param MultipartCapableInterface|null $multipart 可选分片上传
     * @param CloudClientAwareInterface|null $client 可选底层 SDK 客户端
     * @param string $driver 驱动标识（local、aws_s3 等）
     */
    public function __construct(
        private ObjectStoreCapableInterface $store,
        private ?ObjectMetadataCapableInterface $metadata = null,
        private ?ObjectUrlCapableInterface $url = null,
        private ?MultipartCapableInterface $multipart = null,
        private ?CloudClientAwareInterface $client = null,
        private string $driver = '',
    ) {
    }

    /**
     * 当前磁盘驱动名。
     *
     * @return string
     */
    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * 写入对象全文。
     *
     * @param array<string, mixed> $options 如 visibility、mime
     */
    public function putObject(string $path, string $contents, array $options = []): void
    {
        $this->store->putObject(PathNormalizer::normalize($path), $contents, $options);
    }

    /**
     * 以流写入对象，适合大文件。
     *
     * @param resource $stream
     * @param array<string, mixed> $options
     */
    public function putObjectStream(string $path, $stream, array $options = []): void
    {
        $this->store->putObjectStream(PathNormalizer::normalize($path), $stream, $options);
    }

    /**
     * 读取对象全文。
     *
     * @return string
     */
    public function getObject(string $path): string
    {
        return $this->store->getObject(PathNormalizer::normalize($path));
    }

    /**
     * 以流读取对象。
     *
     * @return resource
     */
    public function getObjectStream(string $path)
    {
        return $this->store->getObjectStream(PathNormalizer::normalize($path));
    }

    /**
     * 删除单个对象。
     */
    public function delete(string $path): void
    {
        $this->store->delete(PathNormalizer::normalize($path));
    }

    /**
     * 删除目录前缀下所有对象（语义由驱动定义）。
     */
    public function deleteDirectory(string $path): void
    {
        $this->store->deleteDirectory(PathNormalizer::normalize($path));
    }

    /**
     * 对象是否存在。
     */
    public function fileExists(string $path): bool
    {
        return $this->store->fileExists(PathNormalizer::normalize($path));
    }

    /**
     * 目录前缀下是否有对象（云存储多为前缀语义）。
     */
    public function directoryExists(string $path): bool
    {
        return $this->store->directoryExists(PathNormalizer::normalize($path));
    }

    /**
     * 服务端复制对象。
     */
    public function copy(string $source, string $destination): void
    {
        $this->store->copy(
            PathNormalizer::normalize($source),
            PathNormalizer::normalize($destination)
        );
    }

    /**
     * 移动对象（通常 copy + delete）。
     */
    public function move(string $source, string $destination): void
    {
        $this->store->move(
            PathNormalizer::normalize($source),
            PathNormalizer::normalize($destination)
        );
    }

    /**
     * 列举路径下内容；空 path 表示根前缀。
     *
     * @return iterable<array{path: string, type: string}>
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        // 根列举允许空字符串，不经过会抛错的 normalize
        $normalized = $path === '' ? '' : PathNormalizer::normalize($path);

        return $this->store->listContents($normalized, $deep);
    }

    /**
     * 获取完整元数据（需 metadata Capability）。
     */
    public function getMetadata(string $path): ObjectMetadata
    {
        return $this->metadata()->getMetadata(PathNormalizer::normalize($path));
    }

    /**
     * 最后修改时间 Unix 秒。
     */
    public function lastModified(string $path): int
    {
        return $this->metadata()->lastModified(PathNormalizer::normalize($path));
    }

    /**
     * 与 lastModified 同义，兼容旧调用。
     */
    public function getTimestamp(string $path): int
    {
        return $this->metadata()->getTimestamp(PathNormalizer::normalize($path));
    }

    /**
     * 对象字节大小。
     */
    public function fileSize(string $path): int
    {
        return $this->metadata()->fileSize(PathNormalizer::normalize($path));
    }

    /**
     * MIME 类型。
     */
    public function mimeType(string $path): string
    {
        return $this->metadata()->mimeType(PathNormalizer::normalize($path));
    }

    /**
     * 公开访问 URL（若驱动支持）。
     */
    public function getObjectUrl(string $path): string
    {
        return $this->url()->getObjectUrl(PathNormalizer::normalize($path));
    }

    /**
     * 预签名 URL，相对当前时刻 timeout 秒后失效。
     *
     * @param array<string, mixed> $options
     */
    public function signObjectUrl(string $path, int $timeout, array $options = []): string
    {
        return $this->url()->signObjectUrl(PathNormalizer::normalize($path), $timeout, $options);
    }

    /**
     * 预签名 URL，expiration 为绝对时刻字符串。
     *
     * @param array<string, mixed> $options
     */
    public function getTemporaryUrl(string $path, string $expiration, array $options = []): string
    {
        return $this->url()->getTemporaryUrl(PathNormalizer::normalize($path), $expiration, $options);
    }

    /**
     * 一站式 multipart 上传（需 multipart Capability）。
     *
     * @param resource|string $source 本地路径或可读流
     * @param array<string, mixed> $options
     */
    public function putObjectMultipart(string $path, $source, array $options = []): ObjectMetadata
    {
        return $this->multipart()->putObjectMultipart(PathNormalizer::normalize($path), $source, $options);
    }

    /**
     * 初始化分片上传，返回 uploadId。
     *
     * @param array<string, mixed> $options
     */
    public function createMultipartUpload(string $path, array $options = []): string
    {
        return $this->multipart()->createMultipartUpload(PathNormalizer::normalize($path), $options);
    }

    /**
     * 上传单个分片，返回 part ETag。
     *
     * @param resource|string $body
     * @param array<string, mixed> $options
     */
    public function uploadPart(string $path, string $uploadId, int $partNumber, $body, array $options = []): string
    {
        return $this->multipart()->uploadPart(
            PathNormalizer::normalize($path),
            $uploadId,
            $partNumber,
            $body,
            $options
        );
    }

    /**
     * 合并分片完成上传。
     *
     * @param list<array{partNumber: int, etag: string}> $parts
     * @param array<string, mixed> $options
     */
    public function completeMultipartUpload(string $path, string $uploadId, array $parts, array $options = []): ObjectMetadata
    {
        return $this->multipart()->completeMultipartUpload(
            PathNormalizer::normalize($path),
            $uploadId,
            $parts,
            $options
        );
    }

    /**
     * 中止未完成的分片上传。
     */
    public function abortMultipartUpload(string $path, string $uploadId): void
    {
        $this->multipart()->abortMultipartUpload(PathNormalizer::normalize($path), $uploadId);
    }

    /**
     * 暴露底层 SDK 客户端（需 client Capability）。
     *
     * @return object 如 S3Client、OSS Client
     */
    public function getClient(): object
    {
        return $this->client()->getClient();
    }

    /**
     * 是否注入某 Capability 接口（class-string）。
     *
     * @param class-string $capability
     */
    public function supports(string $capability): bool
    {
        return match ($capability) {
            ObjectStoreCapableInterface::class => true,
            ObjectMetadataCapableInterface::class => $this->metadata !== null,
            ObjectUrlCapableInterface::class => $this->url !== null,
            MultipartCapableInterface::class => $this->multipart !== null,
            CloudClientAwareInterface::class => $this->client !== null,
            default => false,
        };
    }

    /**
     * @return ObjectMetadataCapableInterface
     */
    private function metadata(): ObjectMetadataCapableInterface
    {
        return CapabilityGuard::require($this->metadata, 'metadata');
    }

    /**
     * @return ObjectUrlCapableInterface
     */
    private function url(): ObjectUrlCapableInterface
    {
        return CapabilityGuard::require($this->url, 'url');
    }

    /**
     * @return MultipartCapableInterface
     */
    private function multipart(): MultipartCapableInterface
    {
        return CapabilityGuard::require($this->multipart, 'multipart');
    }

    /**
     * @return CloudClientAwareInterface
     */
    private function client(): CloudClientAwareInterface
    {
        return CapabilityGuard::require($this->client, 'client');
    }
}
