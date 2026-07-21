<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Contracts;

/**
 * 对象存储基础能力：读写删与列举（path 由 FileDisk 规范化后传入）。
 */
interface ObjectStoreCapableInterface
{
    /**
     * 写入对象全文。
     *
     * @param array<string, mixed> $options
     */
    public function putObject(string $path, string $contents, array $options = []): void;

    /**
     * 流式写入对象。
     *
     * @param resource $stream
     * @param array<string, mixed> $options
     */
    public function putObjectStream(string $path, $stream, array $options = []): void;

    /** 读取对象全文。 */
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
}
