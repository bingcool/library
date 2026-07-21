<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Adapter\TencentCos;

use Qcloud\Cos\Client;
use Qcloud\Cos\Exception\ServiceResponseException;
use Swoolefy\Library\FileStorageSystem\Contracts\CloudClientAwareInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\MultipartCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectMetadataCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectStoreCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectUrlCapableInterface;
use Swoolefy\Library\FileStorageSystem\Credential\CredentialProviderInterface;
use Swoolefy\Library\FileStorageSystem\Exception\MultipartUploadException;
use Swoolefy\Library\FileStorageSystem\Exception\ObjectNotFoundException;
use Swoolefy\Library\FileStorageSystem\Exception\PermissionDeniedException;
use Swoolefy\Library\FileStorageSystem\Exception\UploadFailedException;
use Swoolefy\Library\FileStorageSystem\Support\EtagNormalizer;
use Swoolefy\Library\FileStorageSystem\Support\ExpirationParser;
use Swoolefy\Library\FileStorageSystem\Support\SdkGuard;
use Swoolefy\Library\FileStorageSystem\ValueObject\ObjectMetadata;

/**
 * 腾讯云 COS（qcloud/cos-sdk-v5）适配器。
 */
final class TencentCosAdapter implements
    ObjectStoreCapableInterface,
    ObjectMetadataCapableInterface,
    ObjectUrlCapableInterface,
    MultipartCapableInterface,
    CloudClientAwareInterface
{
    private Client $client;

    private int $partSize;

    /**
     * @param int $multipartPartSize 分片大小，COS 建议不低于 1MB
     * @param Client|null $client 单测注入
     */
    public function __construct(
        private string $bucket,
        private CredentialProviderInterface $credentials,
        private string $region = 'ap-guangzhou',
        private ?string $appId = null,
        private ?string $endpoint = null,
        int $multipartPartSize = 8 * 1024 * 1024,
        ?Client $client = null,
    ) {
        // 腾讯云 COS SDK 官方 Client；未安装时提示 composer require qcloud/cos-sdk-v5
        SdkGuard::requireClass(Client::class, 'qcloud/cos-sdk-v5', 'tengxun_cos');
        $this->partSize = max(1024 * 1024, $multipartPartSize);
        $this->client = $client ?? $this->buildClient();
    }

    /**
     * @return Client
     */
    public function getClient(): object
    {
        return $this->client;
    }

    /**
     * 写入对象全文。
     */
    public function putObject(string $path, string $contents, array $options = []): void
    {
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Body' => $contents,
                'ContentType' => $options['mime'] ?? 'application/octet-stream',
            ]);
        } catch (\Throwable $e) {
            throw $this->map($e);
        }
    }

    /**
     * 流式写入对象。
     */
    public function putObjectStream(string $path, $stream, array $options = []): void
    {
        if (!is_resource($stream)) {
            throw new UploadFailedException('putObjectStream expects a resource');
        }
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Body' => $stream,
                'ContentType' => $options['mime'] ?? 'application/octet-stream',
            ]);
        } catch (\Throwable $e) {
            throw $this->map($e);
        }
    }

    /**
     * 读取对象全文。
     */
    public function getObject(string $path): string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);
            $body = $result['Body'] ?? '';

            return is_object($body) && method_exists($body, '__toString')
                ? (string) $body
                : (string) $body;
        } catch (\Throwable $e) {
            throw $this->map($e, $path);
        }
    }

    /**
     * 以内存临时流返回对象内容。
     */
    public function getObjectStream(string $path)
    {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new UploadFailedException('Unable to open temp stream');
        }
        fwrite($stream, $this->getObject($path));
        rewind($stream);

        return $stream;
    }

    /**
     * 删除单个对象。
     */
    public function delete(string $path): void
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);
        } catch (\Throwable $e) {
            throw $this->map($e);
        }
    }

    /**
     * 删除前缀下所有对象。
     */
    public function deleteDirectory(string $path): void
    {
        foreach ($this->listContents($path, true) as $item) {
            if (($item['type'] ?? '') === 'file') {
                $this->delete($item['path']);
            }
        }
    }

    /**
     * 对象是否存在。
     */
    public function fileExists(string $path): bool
    {
        try {
            $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 前缀下是否存在子对象。
     */
    public function directoryExists(string $path): bool
    {
        foreach ($this->listContents($path, false) as $_) {
            return true;
        }

        return false;
    }

    /**
     * 服务端复制对象。
     */
    public function copy(string $source, string $destination): void
    {
        try {
            $this->client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $destination,
                'CopySource' => $this->bucket . '/' . $source,
            ]);
        } catch (\Throwable $e) {
            throw $this->map($e);
        }
    }

    /**
     * 复制后删除源对象。
     */
    public function move(string $source, string $destination): void
    {
        $this->copy($source, $destination);
        $this->delete($source);
    }

    /**
     * 列举对象（可选递归）。
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        $prefix = $path === '' ? '' : rtrim($path, '/') . '/';
        try {
            $result = $this->client->listObjects([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'Delimiter' => $deep ? '' : '/',
            ]);
        } catch (\Throwable $e) {
            throw $this->map($e);
        }
        foreach ($result['Contents'] ?? [] as $obj) {
            $key = (string) ($obj['Key'] ?? '');
            if ($key === '' || $key === $prefix) {
                continue;
            }
            yield ['path' => $key, 'type' => 'file'];
        }
        foreach ($result['CommonPrefixes'] ?? [] as $cp) {
            $p = rtrim((string) ($cp['Prefix'] ?? ''), '/');
            if ($p !== '') {
                yield ['path' => $p, 'type' => 'dir'];
            }
        }
    }

    /**
     * Head 元数据，ETag 经 EtagNormalizer。
     */
    public function getMetadata(string $path): ObjectMetadata
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);
        } catch (\Throwable $e) {
            throw $this->map($e, $path);
        }

        $modified = null;
        if (!empty($result['LastModified'])) {
            $ts = strtotime((string) $result['LastModified']);
            $modified = $ts === false ? null : $ts;
        }

        return new ObjectMetadata(
            size: isset($result['ContentLength']) ? (int) $result['ContentLength'] : null,
            mime: isset($result['ContentType']) ? (string) $result['ContentType'] : null,
            etag: EtagNormalizer::normalize(isset($result['ETag']) ? (string) $result['ETag'] : null),
            checksum: null,
            created: null,
            modified: $modified,
        );
    }

    /**
     * 最后修改 Unix 秒。
     */
    public function lastModified(string $path): int
    {
        return $this->getMetadata($path)->modified ?? 0;
    }

    /**
     * 同 lastModified。
     */
    public function getTimestamp(string $path): int
    {
        return $this->lastModified($path);
    }

    /**
     * 对象大小字节。
     */
    public function fileSize(string $path): int
    {
        return $this->getMetadata($path)->size ?? 0;
    }

    /**
     * Content-Type。
     */
    public function mimeType(string $path): string
    {
        return $this->getMetadata($path)->mime ?? 'application/octet-stream';
    }

    /**
     * 对象访问 URL。
     */
    public function getObjectUrl(string $path): string
    {
        return $this->client->getObjectUrlWithoutSign($this->bucket, $path);
    }

    /**
     * 预签名 URL。
     */
    public function signObjectUrl(string $path, int $timeout, array $options = []): string
    {
        $expires = '+' . max(1, $timeout) . ' seconds';

        return $this->client->getObjectUrl($this->bucket, $path, $expires, $options);
    }

    /**
     * 绝对过期时刻转预签名 URL。
     */
    public function getTemporaryUrl(string $path, string $expiration, array $options = []): string
    {
        return $this->signObjectUrl($path, ExpirationParser::toRelativeSeconds($expiration), $options);
    }

    /**
     * 分片上传；失败 abort，空源 abort。
     *
     * @param resource|string $source
     * @param array<string, mixed> $options
     */
    public function putObjectMultipart(string $path, $source, array $options = []): ObjectMetadata
    {
        $uploadId = $this->createMultipartUpload($path, $options);
        $parts = [];
        $partNumber = 1;
        $in = $this->openSource($source);
        try {
            while (!feof($in)) {
                $chunk = fread($in, $this->partSize);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $etag = $this->uploadPart($path, $uploadId, $partNumber, $chunk, $options);
                $parts[] = ['partNumber' => $partNumber, 'etag' => $etag];
                $partNumber++;
            }
            if ($parts === []) {
                $this->abortMultipartUpload($path, $uploadId);
                throw new MultipartUploadException('Empty multipart source');
            }

            return $this->completeMultipartUpload($path, $uploadId, $parts, $options);
        } catch (\Throwable $e) {
            try {
                $this->abortMultipartUpload($path, $uploadId);
            } catch (\Throwable) {
            }
            throw $e instanceof UploadFailedException ? $e : new MultipartUploadException($e->getMessage(), 0, $e);
        } finally {
            $this->closeSource($source, $in);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createMultipartUpload(string $path, array $options = []): string
    {
        try {
            $result = $this->client->createMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'ContentType' => $options['mime'] ?? 'application/octet-stream',
            ]);

            return (string) ($result['UploadId'] ?? '');
        } catch (\Throwable $e) {
            throw $this->map($e);
        }
    }

    /** ETag 经 EtagNormalizer。 */
    public function uploadPart(string $path, string $uploadId, int $partNumber, $body, array $options = []): string
    {
        try {
            $result = $this->client->uploadPart([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => is_resource($body) ? $body : (string) $body,
            ]);

            return EtagNormalizer::normalize((string) ($result['ETag'] ?? '')) ?? '';
        } catch (\Throwable $e) {
            throw $this->map($e);
        }
    }

    /**
     * @param list<array{partNumber: int, etag: string}> $parts
     * @param array<string, mixed> $options
     */
    public function completeMultipartUpload(string $path, string $uploadId, array $parts, array $options = []): ObjectMetadata
    {
        $cosParts = [];
        foreach ($parts as $part) {
            $cosParts[] = [
                'PartNumber' => (int) $part['partNumber'],
                'ETag' => (string) $part['etag'],
            ];
        }
        try {
            $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'UploadId' => $uploadId,
                'Parts' => $cosParts,
            ]);
        } catch (\Throwable $e) {
            throw $this->map($e);
        }

        return $this->getMetadata($path);
    }

    /**
     * 中止分片上传。
     */
    public function abortMultipartUpload(string $path, string $uploadId): void
    {
        try {
            $this->client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'UploadId' => $uploadId,
            ]);
        } catch (\Throwable $e) {
            throw $this->map($e);
        }
    }

    /**
     * 构建 SDK 客户端。
     */
    private function buildClient(): Client
    {
        $creds = $this->credentials->getCredentials();
        $config = [
            'region' => $this->region,
            'scheme' => 'https',
            'credentials' => [
                'secretId' => $creds['key'],
                'secretKey' => $creds['secret'],
                'token' => $creds['token'] ?? null,
                'appId' => $this->appId,
            ],
        ];
        if ($this->endpoint !== null && $this->endpoint !== '') {
            $config['endpoint'] = $this->endpoint;
        }

        return new Client($config);
    }

    /**
     * ServiceResponseException 映射 404/403；消息含 nosuchkey 时视为未找到。
     */
    private function map(\Throwable $e, ?string $path = null): \Throwable
    {
        if ($e instanceof ServiceResponseException) {
            $status = (int) $e->getStatusCode();
            if ($status === 404) {
                return new ObjectNotFoundException($path ?? '', $e);
            }
            if ($status === 403) {
                return new PermissionDeniedException($e->getMessage(), 0, $e);
            }
        }
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'nosuchkey') || str_contains($msg, '404') || str_contains($msg, 'not found')) {
            return new ObjectNotFoundException($path ?? '', $e);
        }

        return new UploadFailedException($e->getMessage(), 0, $e);
    }

    /** @param resource|string $source @return resource */
    private function openSource($source)
    {
        if (is_resource($source)) {
            return $source;
        }
        if (!is_string($source) || !is_file($source)) {
            throw new UploadFailedException('Multipart source must be file path or resource');
        }
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new UploadFailedException("Unable to open: {$source}");
        }

        return $in;
    }

    /** @param resource|string $source @param resource $handle */
    private function closeSource($source, $handle): void
    {
        if (is_string($source) && is_resource($handle)) {
            fclose($handle);
        }
    }
}
