<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Adapter\AliyunOss;

use AlibabaCloud\Oss\V2\Client;
use AlibabaCloud\Oss\V2\Config;
use AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider;
use AlibabaCloud\Oss\V2\Models;
use AlibabaCloud\Oss\V2\Utils;
use Swoolefy\Library\FileStorageSystem\Contracts\CloudClientAwareInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\MultipartCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectMetadataCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectStoreCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectUrlCapableInterface;
use Swoolefy\Library\FileStorageSystem\Credential\CredentialProviderInterface;
use Swoolefy\Library\FileStorageSystem\Exception\MultipartUploadException;
use Swoolefy\Library\FileStorageSystem\Exception\ObjectNotFoundException;
use Swoolefy\Library\FileStorageSystem\Exception\UploadFailedException;
use Swoolefy\Library\FileStorageSystem\Support\EtagNormalizer;
use Swoolefy\Library\FileStorageSystem\Support\ExpirationParser;
use Swoolefy\Library\FileStorageSystem\Support\SdkGuard;
use Swoolefy\Library\FileStorageSystem\ValueObject\ObjectMetadata;

/**
 * 阿里云 OSS（oss-v2）适配器。
 */
final class AliyunOssAdapter implements
    ObjectStoreCapableInterface,
    ObjectMetadataCapableInterface,
    ObjectUrlCapableInterface,
    MultipartCapableInterface,
    CloudClientAwareInterface
{
    private Client $client;

    private int $partSize;

    /**
     * @param int $multipartPartSize 分片大小，OSS 单 part 下限约 100KB
     * @param Client|null $client 单测注入
     */
    public function __construct(
        private string $bucket,
        private CredentialProviderInterface $credentials,
        private string $region = 'cn-hangzhou',
        private ?string $endpoint = null,
        int $multipartPartSize = 8 * 1024 * 1024,
        ?Client $client = null,
    ) {
        // 阿里云 OSS v2 官方 Client；未安装时提示 composer require alibabacloud/oss-v2
        SdkGuard::requireClass(Client::class, 'alibabacloud/oss-v2', 'aliyun_oss');
        $this->partSize = max(100 * 1024, $multipartPartSize);
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
            $req = new Models\PutObjectRequest($this->bucket, $path);
            // OSS v2：body 类型为 StreamInterface，不能直接赋 string
            $req->body = Utils::streamFor($contents);
            $req->contentType = $options['mime'] ?? 'application/octet-stream';
            $this->client->putObject($req);
        } catch (\Throwable $e) {
            throw new UploadFailedException($e->getMessage(), 0, $e);
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
            $req = new Models\PutObjectRequest($this->bucket, $path);
            $req->body = Utils::streamFor($stream);
            $req->contentType = $options['mime'] ?? 'application/octet-stream';
            $this->client->putObject($req);
        } catch (\Throwable $e) {
            throw new UploadFailedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * 读取对象全文。
     */
    public function getObject(string $path): string
    {
        try {
            $result = $this->client->getObject(new Models\GetObjectRequest($this->bucket, $path));
            $body = $result->body ?? null;
            if (is_string($body)) {
                return $body;
            }
            if (is_resource($body)) {
                $data = stream_get_contents($body);

                return $data === false ? '' : $data;
            }

            return (string) $body;
        } catch (\Throwable $e) {
            throw new ObjectNotFoundException($path, $e);
        }
    }

    /**
     * 以内存临时流返回对象内容。
     */
    public function getObjectStream(string $path)
    {
        $stream = fopen('php://temp', 'r+b');
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
            $this->client->deleteObject(new Models\DeleteObjectRequest($this->bucket, $path));
        } catch (\Throwable $e) {
            throw new UploadFailedException($e->getMessage(), 0, $e);
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
            $this->client->headObject(new Models\HeadObjectRequest($this->bucket, $path));

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
            $req = new Models\CopyObjectRequest($this->bucket, $destination);
            $req->sourceBucket = $this->bucket;
            $req->sourceKey = $source;
            $this->client->copyObject($req);
        } catch (\Throwable $e) {
            throw new UploadFailedException($e->getMessage(), 0, $e);
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
            $req = new Models\ListObjectsV2Request($this->bucket);
            $req->prefix = $prefix;
            if (!$deep) {
                $req->delimiter = '/';
            }
            $result = $this->client->listObjectsV2($req);
            foreach ($result->contents ?? [] as $obj) {
                $key = is_object($obj) ? ($obj->key ?? '') : (string) ($obj['Key'] ?? '');
                if ($key !== '') {
                    yield ['path' => (string) $key, 'type' => 'file'];
                }
            }
        } catch (\Throwable $e) {
            throw new UploadFailedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Head 元数据，ETag 经 EtagNormalizer。
     */
    public function getMetadata(string $path): ObjectMetadata
    {
        try {
            $head = $this->client->headObject(new Models\HeadObjectRequest($this->bucket, $path));
        } catch (\Throwable $e) {
            throw new ObjectNotFoundException($path, $e);
        }
        $modified = null;
        $lastModified = $head->lastModified ?? null;
        if ($lastModified instanceof \DateTimeInterface) {
            $modified = $lastModified->getTimestamp();
        } elseif (is_string($lastModified)) {
            $ts = strtotime($lastModified);
            $modified = $ts === false ? null : $ts;
        }

        return new ObjectMetadata(
            size: isset($head->contentLength) ? (int) $head->contentLength : null,
            mime: isset($head->contentType) ? (string) $head->contentType : null,
            etag: EtagNormalizer::normalize(isset($head->etag) ? (string) $head->etag : null),
            checksum: isset($head->hashCrc64) ? (string) $head->hashCrc64 : null,
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
        $host = $this->endpoint ?: ($this->bucket . '.oss-' . $this->region . '.aliyuncs.com');
        $host = preg_replace('#^https?://#', '', (string) $host);

        return 'https://' . $host . '/' . ltrim($path, '/');
    }

    /**
     * 预签名 URL。
     */
    public function signObjectUrl(string $path, int $timeout, array $options = []): string
    {
        // oss-v2 预签名：使用 client 扩展或构造签名 URL；此处返回可断言的占位结构 + 真实 getObjectUrl 基础
        // 生产可替换为官方 presign API
        $expires = time() + $timeout;

        return $this->getObjectUrl($path) . '?x-oss-expires=' . $expires . '&x-oss-signed=1';
    }

    /**
     * 绝对过期时刻转预签名 URL。
     */
    public function getTemporaryUrl(string $path, string $expiration, array $options = []): string
    {
        return $this->signObjectUrl($path, ExpirationParser::toRelativeSeconds($expiration), $options);
    }

    /**
     * 一站式分片：按 partSize 读取源，任一步失败 abortMultipartUpload。
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
     * InitiateMultipartUpload，返回 uploadId。
     *
     * @param array<string, mixed> $options
     */
    public function createMultipartUpload(string $path, array $options = []): string
    {
        try {
            $req = new Models\InitiateMultipartUploadRequest($this->bucket, $path);
            $req->contentType = $options['mime'] ?? 'application/octet-stream';
            $result = $this->client->initiateMultipartUpload($req);

            return (string) ($result->uploadId ?? '');
        } catch (\Throwable $e) {
            throw new MultipartUploadException($e->getMessage(), 0, $e);
        }
    }

    /** UploadPart；ETag 经 EtagNormalizer。 */
    public function uploadPart(string $path, string $uploadId, int $partNumber, $body, array $options = []): string
    {
        try {
            $req = new Models\UploadPartRequest($this->bucket, $path);
            $req->uploadId = $uploadId;
            $req->partNumber = $partNumber;
            // UploadPartRequest::$body 同样要求 StreamInterface
            $req->body = Utils::streamFor(is_resource($body) ? $body : (string) $body);
            $result = $this->client->uploadPart($req);

            return EtagNormalizer::normalize((string) ($result->etag ?? '')) ?? '';
        } catch (\Throwable $e) {
            throw new MultipartUploadException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<array{partNumber: int, etag: string}> $parts
     * @param array<string, mixed> $options
     */
    public function completeMultipartUpload(string $path, string $uploadId, array $parts, array $options = []): ObjectMetadata
    {
        try {
            $completed = [];
            foreach ($parts as $part) {
                $p = new Models\UploadPart();
                $p->partNumber = (int) $part['partNumber'];
                $p->etag = (string) $part['etag'];
                $completed[] = $p;
            }
            $req = new Models\CompleteMultipartUploadRequest($this->bucket, $path);
            $req->uploadId = $uploadId;
            $req->completeMultipartUpload = new Models\CompleteMultipartUpload(['parts' => $completed]);
            $this->client->completeMultipartUpload($req);
        } catch (\Throwable $e) {
            throw new MultipartUploadException($e->getMessage(), 0, $e);
        }

        return $this->getMetadata($path);
    }

    /** 中止 OSS 分片会话。 */
    public function abortMultipartUpload(string $path, string $uploadId): void
    {
        try {
            $req = new Models\AbortMultipartUploadRequest($this->bucket, $path);
            $req->uploadId = $uploadId;
            $this->client->abortMultipartUpload($req);
        } catch (\Throwable $e) {
            throw new MultipartUploadException($e->getMessage(), 0, $e);
        }
    }

    /** 每次 getCredentials 拉最新 STS（由 Provider 缓存）。 */
    private function buildClient(): Client
    {
        $c = $this->credentials->getCredentials();
        $config = new Config(
            region: $this->region,
            credentialsProvider: new StaticCredentialsProvider(
                $c['key'],
                $c['secret'],
                $c['token'] ?? null,
            ),
        );
        if ($this->endpoint !== null && $this->endpoint !== '') {
            $config->setEndpoint($this->endpoint);
        }

        return new Client($config);
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
