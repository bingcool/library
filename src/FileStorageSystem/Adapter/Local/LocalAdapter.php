<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Adapter\Local;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Swoolefy\Library\FileStorageSystem\Contracts\MultipartCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectMetadataCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectStoreCapableInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\ObjectUrlCapableInterface;
use Swoolefy\Library\FileStorageSystem\Exception\MultipartUploadException;
use Swoolefy\Library\FileStorageSystem\Exception\ObjectNotFoundException;
use Swoolefy\Library\FileStorageSystem\Exception\ObjectUrlNotSupportedException;
use Swoolefy\Library\FileStorageSystem\Exception\UnsupportedCapabilityException;
use Swoolefy\Library\FileStorageSystem\Exception\UploadFailedException;
use Swoolefy\Library\FileStorageSystem\Support\SdkGuard;
use Swoolefy\Library\FileStorageSystem\ValueObject\ObjectMetadata;

/**
 * 本地磁盘：Flysystem Local；multipart = 临时文件 + stream copy + rename。
 */
final class LocalAdapter implements
    ObjectStoreCapableInterface,
    ObjectMetadataCapableInterface,
    ObjectUrlCapableInterface,
    MultipartCapableInterface
{
    private Filesystem $fs;

    /** @var array<string, array{path: string, temp: string, handle: resource}> */
    private array $multipartSessions = [];

    /**
     * @param string $root 本地存储根目录（自动创建）
     * @param string|null $publicBaseUrl 配置后 getObjectUrl 可拼公开 URL
     */
    public function __construct(
        private string $root,
        private ?string $publicBaseUrl = null,
    ) {
        // Flysystem 官方类：未 composer install 时给出明确安装提示
        SdkGuard::requireClass(Filesystem::class, 'league/flysystem', 'local');
        SdkGuard::requireClass(LocalFilesystemAdapter::class, 'league/flysystem', 'local');

        if (!is_dir($this->root) && !@mkdir($this->root, 0775, true) && !is_dir($this->root)) {
            throw new UploadFailedException("Unable to create local root: {$this->root}");
        }
        $this->root = rtrim($this->root, '/\\');
        $this->fs = new Filesystem(new LocalFilesystemAdapter($this->root));
    }

    /**
     * 写入字符串内容到相对 path（path 已由 FileDisk 规范化）。
     *
     * @param array<string, mixed> $options Flysystem visibility 等
     */
    public function putObject(string $path, string $contents, array $options = []): void
    {
        try {
            $this->fs->write($path, $contents, $this->flyConfig($options));
        } catch (\Throwable $e) {
            throw new UploadFailedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * 流式写入，避免大文件整段载入内存。
     *
     * @param resource $stream
     * @param array<string, mixed> $options
     */
    public function putObjectStream(string $path, $stream, array $options = []): void
    {
        if (!is_resource($stream)) {
            throw new UploadFailedException('putObjectStream expects a resource');
        }
        try {
            $this->fs->writeStream($path, $stream, $this->flyConfig($options));
        } catch (\Throwable $e) {
            throw new UploadFailedException($e->getMessage(), 0, $e);
        }
    }

    /** 读取全文；Flysystem 读失败映射为 ObjectNotFoundException。 */
    public function getObject(string $path): string
    {
        try {
            return $this->fs->read($path);
        } catch (UnableToReadFile $e) {
            throw new ObjectNotFoundException($path, $e);
        } catch (\Throwable $e) {
            throw new ObjectNotFoundException($path, $e);
        }
    }

    /**
     * @return resource 可读流
     */
    public function getObjectStream(string $path)
    {
        try {
            return $this->fs->readStream($path);
        } catch (UnableToReadFile $e) {
            throw new ObjectNotFoundException($path, $e);
        }
    }

    /** 存在则删除，不存在不报错。 */
    public function delete(string $path): void
    {
        if ($this->fs->fileExists($path)) {
            $this->fs->delete($path);
        }
    }

    /** 目录存在则递归删除。 */
    public function deleteDirectory(string $path): void
    {
        if ($this->fs->directoryExists($path)) {
            $this->fs->deleteDirectory($path);
        }
    }

    /** 文件是否存在。 */
    public function fileExists(string $path): bool
    {
        return $this->fs->fileExists($path);
    }

    /** 目录是否存在。 */
    public function directoryExists(string $path): bool
    {
        return $this->fs->directoryExists($path);
    }

    /** Flysystem 服务端复制。 */
    public function copy(string $source, string $destination): void
    {
        $this->fs->copy($source, $destination);
    }

    /** Flysystem 移动（同卷 rename 语义）。 */
    public function move(string $source, string $destination): void
    {
        $this->fs->move($source, $destination);
    }

    /**
     * @return iterable<array{path: string, type: string}>
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        foreach ($this->fs->listContents($path, $deep) as $item) {
            yield [
                'path' => $item->path(),
                'type' => $item->isFile() ? 'file' : 'dir',
            ];
        }
    }

    /**
     * 聚合 size/mime/etag；本地 etag 为文件 md5。
     */
    public function getMetadata(string $path): ObjectMetadata
    {
        if (!$this->fs->fileExists($path)) {
            throw new ObjectNotFoundException($path);
        }
        try {
            $size = $this->fs->fileSize($path);
            $mime = $this->fs->mimeType($path);
            $modified = $this->fs->lastModified($path);
        } catch (UnableToRetrieveMetadata $e) {
            throw new ObjectNotFoundException($path, $e);
        }
        $absolute = $this->absolutePath($path);
        $etag = is_file($absolute) ? md5_file($absolute) : null;

        return new ObjectMetadata(
            size: $size,
            mime: $mime,
            etag: $etag ?: null,
            checksum: $etag ?: null,
            created: null,
            modified: $modified,
        );
    }

    /** 最后修改 Unix 秒。 */
    public function lastModified(string $path): int
    {
        return $this->getMetadata($path)->modified ?? 0;
    }

    /** 与 lastModified 相同。 */
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

    /**
     * 基于 publicBaseUrl 拼接静态 URL；未配置则抛 ObjectUrlNotSupportedException。
     */
    public function getObjectUrl(string $path): string
    {
        if ($this->publicBaseUrl === null || $this->publicBaseUrl === '') {
            throw new ObjectUrlNotSupportedException('Local publicBaseUrl not configured');
        }

        return rtrim($this->publicBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    /** 本地盘不支持预签名。 */
    public function signObjectUrl(string $path, int $timeout, array $options = []): string
    {
        throw new ObjectUrlNotSupportedException('Local disk does not support signed URLs');
    }

    /** 本地盘不支持临时 URL。 */
    public function getTemporaryUrl(string $path, string $expiration, array $options = []): string
    {
        throw new ObjectUrlNotSupportedException('Local disk does not support temporary URLs');
    }

    /**
     * 大文件上传：写入 .uploading 临时文件，stream_copy_to_stream 后原子 rename。
     *
     * @param resource|string $source
     * @param array<string, mixed> $options
     */
    public function putObjectMultipart(string $path, $source, array $options = []): ObjectMetadata
    {
        $absolute = $this->absolutePath($path);
        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UploadFailedException("Unable to create directory: {$dir}");
        }

        $temp = $absolute . '.uploading.' . bin2hex(random_bytes(8));
        $in = $this->openSource($source);
        $out = fopen($temp, 'wb');
        if ($out === false) {
            $this->closeSource($source, $in);
            throw new UploadFailedException("Unable to open temp file: {$temp}");
        }

        try {
            $copied = stream_copy_to_stream($in, $out); // 大块拷贝，避免整文件读入内存
            if ($copied === false) {
                throw new UploadFailedException('stream_copy_to_stream failed');
            }
            fflush($out);
            fclose($out);
            $out = null;
            if (!@rename($temp, $absolute)) {
                if (!@copy($temp, $absolute) || !@unlink($temp)) {
                    throw new UploadFailedException("Unable to move temp to {$absolute}");
                }
            }
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_file($temp)) {
                @unlink($temp);
            }
            throw $e instanceof UploadFailedException
                ? $e
                : new MultipartUploadException($e->getMessage(), 0, $e);
        } finally {
            $this->closeSource($source, $in);
        }

        return $this->getMetadata($path);
    }

    /**
     * 会话式 multipart：在磁盘上打开单一 temp 文件，供后续 uploadPart 追加。
     *
     * @param array<string, mixed> $options
     */
    public function createMultipartUpload(string $path, array $options = []): string
    {
        $uploadId = 'local_upload_' . bin2hex(random_bytes(8));
        $absolute = $this->absolutePath($path);
        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new MultipartUploadException("Unable to create directory: {$dir}");
        }
        $temp = $absolute . '.multipart.' . $uploadId;
        $handle = fopen($temp, 'wb');
        if ($handle === false) {
            throw new MultipartUploadException("Unable to open multipart temp: {$temp}");
        }
        $this->multipartSessions[$uploadId] = [
            'path' => $path,
            'temp' => $temp,
            'handle' => $handle,
        ];

        return $uploadId;
    }

    /**
     * 向会话 temp 追加 part 内容；返回 chunk 的 md5 作伪 ETag。
     *
     * @param resource|string $body
     * @param array<string, mixed> $options
     */
    public function uploadPart(string $path, string $uploadId, int $partNumber, $body, array $options = []): string
    {
        // Local：仅追加写入同一 temp（不推荐业务多 part；主路径用 putObjectMultipart）
        $session = $this->multipartSessions[$uploadId] ?? null;
        if ($session === null) {
            throw new MultipartUploadException("Unknown multipart session: {$uploadId}");
        }
        $chunk = is_resource($body) ? stream_get_contents($body) : (string) $body;
        if ($chunk === false) {
            throw new MultipartUploadException('Unable to read part body');
        }
        fwrite($session['handle'], $chunk);

        return md5($chunk);
    }

    /** 关闭句柄并将 temp rename 为最终对象路径。 */
    public function completeMultipartUpload(string $path, string $uploadId, array $parts, array $options = []): ObjectMetadata
    {
        $session = $this->multipartSessions[$uploadId] ?? null;
        if ($session === null) {
            throw new MultipartUploadException("Unknown multipart session: {$uploadId}");
        }
        fflush($session['handle']);
        fclose($session['handle']);
        $absolute = $this->absolutePath($path);
        if (!@rename($session['temp'], $absolute)) {
            if (!@copy($session['temp'], $absolute) || !@unlink($session['temp'])) {
                unset($this->multipartSessions[$uploadId]);
                throw new MultipartUploadException("Unable to finalize multipart to {$absolute}");
            }
        }
        unset($this->multipartSessions[$uploadId]);

        return $this->getMetadata($path);
    }

    /** 丢弃 temp 文件并移除内存中的 multipart 会话。 */
    public function abortMultipartUpload(string $path, string $uploadId): void
    {
        $session = $this->multipartSessions[$uploadId] ?? null;
        if ($session === null) {
            return;
        }
        if (is_resource($session['handle'])) {
            fclose($session['handle']);
        }
        if (is_file($session['temp'])) {
            @unlink($session['temp']);
        }
        unset($this->multipartSessions[$uploadId]);
    }

    /**
     * 将 options 转为 Flysystem 写配置。
     *
     * @return array<string, mixed>
     */
    private function flyConfig(array $options): array
    {
        $config = [];
        if (isset($options['visibility'])) {
            $config['visibility'] = $options['visibility'];
        }

        return $config;
    }

    /** root 与逻辑 path 拼成 OS 绝对路径。 */
    private function absolutePath(string $path): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * 打开 multipart 源；路径字符串时由本方法 fopen 并负责关闭。
     *
     * @param resource|string $source
     * @return resource
     */
    private function openSource($source)
    {
        if (is_resource($source)) {
            return $source;
        }
        if (!is_string($source) || !is_file($source)) {
            throw new UploadFailedException('Multipart source must be an existing file path or resource');
        }
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new UploadFailedException("Unable to open source: {$source}");
        }

        return $in;
    }

    /**
     * 仅当 source 为文件路径时关闭由 openSource 打开的句柄。
     *
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
