<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Credential;

use Swoolefy\Library\FileStorageSystem\Exception\FileStorageException;

/**
 * STS 凭证：内部按 expires_at 刷新；缓存仅存于本 Provider，禁止写入 FileStorageManager::$disks。
 */
final class StsCredentialProvider implements CredentialProviderInterface
{
    /** @var array{key: string, secret: string, token?: string, expires_at?: int}|null */
    private ?array $cached = null;

    /**
     * @param callable(): array{key: string, secret: string, token?: string, expires_at?: int} $fetcher 拉取 STS 的回调
     */
    public function __construct(
        private $fetcher,
    ) {
    }

    /**
     * 返回有效 STS；距 expires_at 不足 60 秒时提前调用 fetcher 刷新（缓存仅在本类内）。
     *
     * @return array{key: string, secret: string, token?: string, expires_at?: int}
     */
    public function getCredentials(): array
    {
        // 提前 60s 刷新，避免边界时刻 SDK 请求仍用即将过期的 token
        if ($this->cached !== null && ($this->cached['expires_at'] ?? 0) > time() + 60) {
            return $this->cached;
        }

        $creds = ($this->fetcher)();
        if (!isset($creds['key'], $creds['secret'])) {
            throw new FileStorageException('STS fetcher must return key and secret');
        }
        $this->cached = $creds;

        return $this->cached;
    }
}
