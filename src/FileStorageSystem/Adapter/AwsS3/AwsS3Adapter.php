<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Adapter\AwsS3;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Swoolefy\Library\FileStorageSystem\Contracts\CloudClientAwareInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\MultipartCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectMetadataCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectStoreCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectUrlCapableInterface;
use Swoolefy\Library\FileStorageSystem\Credential\AwsCredentialFactory;
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
 * AWS S3 适配器（真实 SDK 调用；无密钥时单测勿打真实网络）。
 */
final class AwsS3Adapter implements
    ObjectStoreCapableInterface,
    ObjectMetadataCapableInterface,
    ObjectUrlCapableInterface,
    MultipartCapableInterface,
    CloudClientAwareInterface
{
    private S3Client $client;

    private int $partSize;

    /**
     * @param bool $usePathStyle 兼容 MinIO 等 path-style endpoint
     * @param int $multipartPartSize 分片大小，S3 单 part 下限 5MB
     * @param S3Client|null $client 单测可注入 mock 客户端
     */
    public function __construct(
        private string $bucket,
        private CredentialProviderInterface $credentials,
        private string $region = 'us-east-1',
        private ?string $endpoint = null,
        private bool $usePathStyle = false,
        int $multipartPartSize = 8 * 1024 * 1024,
        ?S3Client $client = null,
    ) {
        // AWS SDK 官方入口类；未安装时提示 composer require aws/aws-sdk-php
        SdkGuard::requireClass(S3Client::class, 'aws/aws-sdk-php', 'aws_s3');
        // S3 multipart 除最后一片外每 part 至少 5MB
        $this->partSize = max(5 * 1024 * 1024, $multipartPartSize);
        $this->client = $client ?? $this->buildClient();
    }

    /**
     * 暴露 S3Client 供高级场景直接调 SDK。
     *
     * @return S3Client
     */
    public function getClient(): object
    {
        return $this->client;
    }

    /**
     * 单次 PutObject 上传小对象。
     *
     * @param array<string, mixed> $options visibility、mime 等
     */
    public function putObject(string $path, string $contents, array $options = []): void
    {
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Body' => $contents,
                'ContentType' => $options['mime'] ?? 'application/octet-stream',
                'ACL' => ($options['visibility'] ?? '') === 'public' ? 'public-read' : 'private',
            ]);
        } catch (AwsException $e) {
            throw $this->mapAws($e);
        }
    }

    /**
     * @param resource $stream
     * @param array<string, mixed> $options
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
        } catch (AwsException $e) {
            throw $this->mapAws($e);
        }
    }

    /** GetObject 全文；AwsException 经 mapAws 映射。 */
    public function getObject(string $path): string
    {
        try {
            $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $path]);

            return (string) $result['Body'];
        } catch (AwsException $e) {
            throw $this->mapAws($e, $path);
        }
    }

    /**
     * GetObject 流；SDK 返回字符串时落入 php://temp。
     *
     * @return resource
     */
    public function getObjectStream(string $path)
    {
        try {
            $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $path]);
            $body = $result['Body'];
            if (is_resource($body)) {
                return $body;
            }
            $stream = fopen('php://temp', 'r+b');
            fwrite($stream, (string) $body);
            rewind($stream);

            return $stream;
        } catch (AwsException $e) {
            throw $this->mapAws($e, $path);
        }
    }

    /** DeleteObject。 */
    public function delete(string $path): void
    {
        try {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $path]);
        } catch (AwsException $e) {
            throw $this->mapAws($e, $path);
        }
    }

    /** deleteMatchingObjects 按前缀批量删。 */
    public function deleteDirectory(string $path): void
    {
        $prefix = rtrim($path, '/') . '/';
        try {
            $this->client->deleteMatchingObjects($this->bucket, $prefix);
        } catch (AwsException $e) {
            throw $this->mapAws($e, $path);
        }
    }

    /** doesObjectExist 判断对象是否存在。 */
    public function fileExists(string $path): bool
    {
        return $this->client->doesObjectExist($this->bucket, $path);
    }

    /** 列举前缀下至少一个 key 即视为目录存在。 */
    public function directoryExists(string $path): bool
    {
        $prefix = rtrim($path, '/') . '/';
        $result = $this->client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
            'MaxKeys' => 1,
        ]);

        return ($result['KeyCount'] ?? 0) > 0;
    }

    /** CopyObject；CopySource 需 URL 编码 bucket/key。 */
    public function copy(string $source, string $destination): void
    {
        try {
            $this->client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $destination,
                'CopySource' => rawurlencode($this->bucket . '/' . $source),
            ]);
        } catch (AwsException $e) {
            throw $this->mapAws($e);
        }
    }

    /** 复制后删除源对象。 */
    public function move(string $source, string $destination): void
    {
        $this->copy($source, $destination);
        $this->delete($source);
    }

    /**
     * ListObjectsV2；非 deep 时用 Delimiter=/ 模拟单层。
     *
     * @return iterable<array{path: string, type: string}>
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        $prefix = $path === '' ? '' : rtrim($path, '/') . '/';
        $params = ['Bucket' => $this->bucket, 'Prefix' => $prefix];
        if (!$deep) {
            $params['Delimiter'] = '/';
        }
        $result = $this->client->listObjectsV2($params);
        foreach ($result['Contents'] ?? [] as $item) {
            yield ['path' => (string) $item['Key'], 'type' => 'file'];
        }
    }

    /** HeadObject + EtagNormalizer 填充 ObjectMetadata。 */
    public function getMetadata(string $path): ObjectMetadata
    {
        try {
            $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $path]);
        } catch (AwsException $e) {
            throw $this->mapAws($e, $path);
        }
        $modified = null;
        if (isset($head['LastModified']) && $head['LastModified'] instanceof \DateTimeInterface) {
            $modified = $head['LastModified']->getTimestamp();
        }

        return new ObjectMetadata(
            size: isset($head['ContentLength']) ? (int) $head['ContentLength'] : null,
            mime: isset($head['ContentType']) ? (string) $head['ContentType'] : null,
            etag: EtagNormalizer::normalize(isset($head['ETag']) ? (string) $head['ETag'] : null),
            checksum: isset($head['ChecksumCRC64NVME']) ? (string) $head['ChecksumCRC64NVME'] : null,
            created: null,
            modified: $modified,
        );
    }

    /** 最后修改 Unix 秒。 */
    public function lastModified(string $path): int
    {
        return $this->getMetadata($path)->modified ?? 0;
    }

    /** 与 lastModified 同义。 */
    public function getTimestamp(string $path): int
    {
        return $this->lastModified($path);
    }

    /** 对象字节大小。 */
    public function fileSize(string $path): int
    {
        return $this->getMetadata($path)->size ?? 0;
    }

    /** MIME 类型；未知时返回 application/octet-stream。 */
    public function mimeType(string $path): string
    {
        return $this->getMetadata($path)->mime ?? 'application/octet-stream';
    }

    /** SDK 生成的对象 URL（桶/区域默认样式）。 */
    public function getObjectUrl(string $path): string
    {
        return $this->client->getObjectUrl($this->bucket, $path);
    }

    /** createPresignedRequest 预签名 GET。 */
    public function signObjectUrl(string $path, int $timeout, array $options = []): string
    {
        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $path,
        ]);
        $request = $this->client->createPresignedRequest($cmd, "+{$timeout} seconds");

        return (string) $request->getUri();
    }

    /** ExpirationParser 转相对秒数后委托 signObjectUrl。 */
    public function getTemporaryUrl(string $path, string $expiration, array $options = []): string
    {
        return $this->signObjectUrl($path, ExpirationParser::toRelativeSeconds($expiration), $options);
    }

    /**
     * 按 partSize 分片循环 uploadPart，失败 abort；空源同样 abort。
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
            // 任一分片失败则 abort，避免桶内残留未完成 multipart
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
     * 向 S3 申请 uploadId，开启 multipart 会话。
     *
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

            return (string) $result['UploadId'];
        } catch (AwsException $e) {
            throw $this->mapAws($e);
        }
    }

    /** UploadPart；ETag 经 EtagNormalizer 去引号。 */
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

            return EtagNormalizer::normalize((string) $result['ETag']) ?? '';
        } catch (AwsException $e) {
            throw $this->mapAws($e);
        }
    }

    /**
     * CompleteMultipartUpload 后 head 取元数据。
     *
     * @param list<array{partNumber: int, etag: string}> $parts
     * @param array<string, mixed> $options
     */
    public function completeMultipartUpload(string $path, string $uploadId, array $parts, array $options = []): ObjectMetadata
    {
        $awsParts = [];
        foreach ($parts as $part) {
            $awsParts[] = [
                'PartNumber' => (int) $part['partNumber'],
                'ETag' => (string) $part['etag'],
            ];
        }
        try {
            $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $awsParts],
            ]);
        } catch (AwsException $e) {
            throw $this->mapAws($e);
        }

        return $this->getMetadata($path);
    }

    /** AbortMultipartUpload 清理未完成上传。 */
    public function abortMultipartUpload(string $path, string $uploadId): void
    {
        try {
            $this->client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'UploadId' => $uploadId,
            ]);
        } catch (AwsException $e) {
            throw $this->mapAws($e);
        }
    }

    /** 组装 S3Client；凭证来自 AwsCredentialFactory。 */
    private function buildClient(): S3Client
    {
        $config = [
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => AwsCredentialFactory::toAwsArray($this->credentials),
        ];
        if ($this->endpoint !== null && $this->endpoint !== '') {
            $config['endpoint'] = $this->endpoint;
        }
        if ($this->usePathStyle) {
            $config['use_path_style_endpoint'] = true; // path-style：http://endpoint/bucket/key
        }

        return new S3Client($config);
    }

    /**
     * 将 AwsException 映射为库内领域异常（404/NoSuchKey、403、其余 UploadFailed）。
     */
    private function mapAws(AwsException $e, ?string $path = null): \Throwable
    {
        $code = (int) $e->getStatusCode();
        if ($code === 404 || $e->getAwsErrorCode() === 'NoSuchKey' || $e->getAwsErrorCode() === 'NotFound') {
            return new ObjectNotFoundException($path ?? '', $e);
        }
        if ($code === 403) {
            return new PermissionDeniedException($e->getMessage(), 0, $e);
        }

        return new UploadFailedException($e->getMessage(), 0, $e);
    }

    /**
     * @param resource|string $source
     * @return resource
     */
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

    /**
     * @param resource|string $source
     * @param resource $handle
     */
    private function closeSource($source, $handle): void
    {
        if (is_string($source) && is_resource($handle)) {
            fclose($handle);
        }
    }
}
