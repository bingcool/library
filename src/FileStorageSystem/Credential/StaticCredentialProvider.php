<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Credential;

/**
 * 长期或已注入的静态密钥；无内部缓存，每次 getCredentials 返回相同内容。
 */
final class StaticCredentialProvider implements CredentialProviderInterface
{
    /**
     * @param string $key AccessKey / SecretId
     * @param string $secret SecretKey
     * @param string|null $token 可选 STS session token
     */
    public function __construct(
        private string $key,
        private string $secret,
        private ?string $token = null,
    ) {
    }

    /**
     * 返回固定凭证；非空 token 时一并带上（临时密钥场景）。
     *
     * @return array{key: string, secret: string, token?: string}
     */
    public function getCredentials(): array
    {
        $out = ['key' => $this->key, 'secret' => $this->secret];
        if ($this->token !== null && $this->token !== '') {
            $out['token'] = $this->token;
        }

        return $out;
    }
}
