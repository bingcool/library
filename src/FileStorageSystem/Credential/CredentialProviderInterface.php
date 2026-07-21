<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Credential;

/**
 * 云存储访问凭证提供方；STS 实现可带 expires_at 并在内部缓存刷新。
 */
interface CredentialProviderInterface
{
    /**
     * 返回当前可用的 key/secret（及可选 session token）。
     *
     * @return array{key: string, secret: string, token?: string, expires_at?: int}
     */
    public function getCredentials(): array;
}
