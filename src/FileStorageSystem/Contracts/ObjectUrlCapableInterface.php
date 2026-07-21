<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Contracts;

/**
 * 对象 URL 与预签名能力。
 */
interface ObjectUrlCapableInterface
{
    /** 公开或默认样式的对象 URL。 */
    public function getObjectUrl(string $path): string;

    /**
     * @param array<string, mixed> $options
     */
    public function signObjectUrl(string $path, int $timeout, array $options = []): string;

    /**
     * expiration 为绝对时刻字符串（由 ExpirationParser 解析）。
     *
     * @param array<string, mixed> $options
     */
    public function getTemporaryUrl(string $path, string $expiration, array $options = []): string;
}
