<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Credential;

/**
 * 将 CredentialProvider 转为 AWS SDK 所需的 credential 数组或惰性闭包（配合 STS 刷新）。
 */
final class AwsCredentialFactory
{
    /**
     * 快照当前凭证为 AWS SDK credentials 数组（单次 getCredentials；STS 需用 toAwsProvider 才每次请求刷新）。
     *
     * @return array{key: string, secret: string, token?: string}
     */
    public static function toAwsArray(CredentialProviderInterface $provider): array
    {
        $c = $provider->getCredentials();
        $out = [
            'key' => $c['key'],
            'secret' => $c['secret'],
        ];
        if (!empty($c['token'])) {
            $out['token'] = $c['token'];
        }

        return $out;
    }

    /**
     * 返回每次调用都会重新 getCredentials 的闭包，供 S3Client 在 STS 场景下自动拿新 token。
     *
     * @return callable(): array{key: string, secret: string, token?: string}
     */
    public static function toAwsProvider(CredentialProviderInterface $provider): callable
    {
        return static function () use ($provider): array {
            return self::toAwsArray($provider);
        };
    }
}
