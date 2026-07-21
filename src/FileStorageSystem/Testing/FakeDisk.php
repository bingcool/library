<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Testing;

use Swoolefy\Library\FileStorageSystem\Contracts\MultipartCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectMetadataCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectStoreCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectUrlCapableInterface;
use Swoolefy\Library\FileStorageSystem\Exception\ObjectNotFoundException;
use Swoolefy\Library\FileStorageSystem\Exception\UnsupportedCapabilityException;
use Swoolefy\Library\FileStorageSystem\Exception\UploadFailedException;
use Swoolefy\Library\FileStorageSystem\Support\ExpirationParser;
use Swoolefy\Library\FileStorageSystem\ValueObject\ObjectMetadata;

/**
 * 内存盘：单测用；multipart 等同 putObject，禁止假 part 拼接 API。
 */
final class FakeDisk implements
    ObjectStoreCapableInterface,
    ObjectMetadataCapableInterface,
    ObjectUrlCapableInterface,
    MultipartCapableInterface
{
    /** @var array<string, string> */
    private array $objects = [];

    /** @var array<string, ObjectMetadata> */
    private array $meta = [];

    /**
     * @param array<string, mixed> $options mime 等
     */
    public function putObject(string $path, string $contents, array $options = []): void
    {
        $now = time();
        $this->objects[$path] = $contents;
        $this->meta[$path] = new ObjectMetadata(
            size: strlen($contents),
            mime: isset($options['mime']) ? (string) $options['mime'] : 'application/octet-stream',
            etag: md5($contents),
            checksum: md5($contents),
            created: $this->meta[$path]->created ?? $now,
            modified: $now,
        );
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
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new UploadFailedException('Failed to read stream');
        }
        $this->putObject($path, $contents, $options);
    }

    /**
     * @return string
     */
    public function getObject(string $path): string
    {
        $this->assertExists($path);

        return $this->objects[$path];
    }

    /**
     * @return resource
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

    /** 删除内存中的对象与元数据。 */
    public function delete(string $path): void
    {
        unset($this->objects[$path], $this->meta[$path]);
    }

    /** 按前缀删除对象键。 */
    public function deleteDirectory(string $path): void
    {
        $prefix = rtrim($path, '/') . '/';
        foreach (array_keys($this->objects) as $key) {
            if ($key === $path || str_starts_with($key, $prefix)) {
                unset($this->objects[$key], $this->meta[$key]);
            }
        }
    }

    /** 对象是否存在于内存。 */
    public function fileExists(string $path): bool
    {
        return isset($this->objects[$path]);
    }

    /** 前缀下是否存在子对象。 */
    public function directoryExists(string $path): bool
    {
        $prefix = rtrim($path, '/') . '/';
        foreach (array_keys($this->objects) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** 复制对象内容与 mime。 */
    public function copy(string $source, string $destination): void
    {
        $this->putObject($destination, $this->getObject($source), [
            'mime' => $this->getMetadata($source)->mime,
        ]);
    }

    /** 复制后删除源对象。 */
    public function move(string $source, string $destination): void
    {
        $this->copy($source, $destination);
        $this->delete($source);
    }

    /**
     * @return iterable<array{path: string, type: string}>
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        $prefix = $path === '' ? '' : rtrim($path, '/') . '/';
        foreach (array_keys($this->objects) as $key) {
            if ($prefix !== '' && !str_starts_with($key, $prefix) && $key !== $path) {
                continue;
            }
            if (!$deep && $prefix !== '') {
                $rest = substr($key, strlen($prefix));
                if ($rest !== false && str_contains($rest, '/')) {
                    continue;
                }
            }
            yield ['path' => $key, 'type' => 'file'];
        }
    }

    /** 返回内存中缓存的元数据。 */
    public function getMetadata(string $path): ObjectMetadata
    {
        $this->assertExists($path);

        return $this->meta[$path];
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

    /** MIME 类型。 */
    public function mimeType(string $path): string
    {
        return $this->getMetadata($path)->mime ?? 'application/octet-stream';
    }

    /** 固定 fake://  scheme，便于断言。 */
    public function getObjectUrl(string $path): string
    {
        return 'fake://object/' . ltrim($path, '/');
    }

    /** 伪造预签名 URL（含 expires 查询参数）。 */
    public function signObjectUrl(string $path, int $timeout, array $options = []): string
    {
        return 'fake://signed/' . ltrim($path, '/') . '?expires=' . (time() + $timeout);
    }

    /** ExpirationParser 转相对秒数后委托 signObjectUrl。 */
    public function getTemporaryUrl(string $path, string $expiration, array $options = []): string
    {
        $seconds = ExpirationParser::toRelativeSeconds($expiration);

        return $this->signObjectUrl($path, $seconds, $options);
    }

    /**
     * 不模拟分片协议：整段读入后 putObject / putObjectStream。
     *
     * @param resource|string $source
     * @param array<string, mixed> $options
     */
    public function putObjectMultipart(string $path, $source, array $options = []): ObjectMetadata
    {
        if (is_string($source)) {
            if (!is_file($source)) {
                throw new UploadFailedException("Source file not found: {$source}");
            }
            $contents = file_get_contents($source);
            if ($contents === false) {
                throw new UploadFailedException("Unable to read: {$source}");
            }
            $this->putObject($path, $contents, $options);
        } elseif (is_resource($source)) {
            $this->putObjectStream($path, $source, $options);
        } else {
            throw new UploadFailedException('putObjectMultipart source must be path or resource');
        }

        return $this->getMetadata($path);
    }

    /** 禁用会话式 multipart，避免与云语义不一致的假实现。 */
    public function createMultipartUpload(string $path, array $options = []): string
    {
        throw new UnsupportedCapabilityException('FakeDisk: use putObjectMultipart only');
    }

    /** 禁用假 part API，请改用 putObjectMultipart。 */
    public function uploadPart(string $path, string $uploadId, int $partNumber, $body, array $options = []): string
    {
        throw new UnsupportedCapabilityException('FakeDisk: use putObjectMultipart only');
    }

    /** 禁用假 complete API，请改用 putObjectMultipart。 */
    public function completeMultipartUpload(string $path, string $uploadId, array $parts, array $options = []): ObjectMetadata
    {
        throw new UnsupportedCapabilityException('FakeDisk: use putObjectMultipart only');
    }

    /** 无服务端 uploadId 状态，空操作。 */
    public function abortMultipartUpload(string $path, string $uploadId): void
    {
        // FakeDisk 无服务端 uploadId 状态
    }

    /**
     * @throws ObjectNotFoundException
     */
    private function assertExists(string $path): void
    {
        if (!isset($this->objects[$path])) {
            throw new ObjectNotFoundException($path);
        }
    }
}
