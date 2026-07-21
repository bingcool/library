<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem;

use Swoolefy\Library\FileStorageSystem\Adapter\AliyunOss\AliyunOssAdapter;
use Swoolefy\Library\FileStorageSystem\Adapter\AwsS3\AwsS3Adapter;
use Swoolefy\Library\FileStorageSystem\Adapter\Local\LocalAdapter;
use Swoolefy\Library\FileStorageSystem\Adapter\TencentCos\TencentCosAdapter;
use Swoolefy\Library\FileStorageSystem\Credential\CredentialProviderInterface;
use Swoolefy\Library\FileStorageSystem\Credential\StaticCredentialProvider;
use Swoolefy\Library\FileStorageSystem\Credential\StsCredentialProvider;
use Swoolefy\Library\FileStorageSystem\Exception\InvalidProviderException;
use Swoolefy\Library\FileStorageSystem\Testing\FakeDisk;

/**
 * 按 driver 配置组装 FileDisk；云适配器同一实例挂接多种 Capability。
 */
final class FileStorageFactory
{
    /**
     * 根据 provider 配置创建 FileDisk。
     *
     * @param string $name 配置键名（仅用于异常信息）
     * @param array<string, mixed> $config 含 driver 及各驱动专有项
     * @return FileDisk
     */
    public function make(string $name, array $config): FileDisk
    {
        $driver = (string) ($config['driver'] ?? '');
        if ($driver === '') {
            throw new InvalidProviderException("Provider [{$name}] missing driver");
        }

        return match ($driver) {
            'local' => $this->makeLocal($config),
            'fake' => $this->makeFake(),
            'aws_s3' => $this->makeAwsS3($config),
            'aliyun_oss' => $this->makeAliyunOss($config),
            'tengxun_cos' => $this->makeTencentCos($config),
            default => throw new InvalidProviderException("Unknown driver [{$driver}] for provider [{$name}]"),
        };
    }

    /**
     * 本地 Flysystem：单 Adapter 实现 store/metadata/url/multipart 四类能力。
     *
     * @param array<string, mixed> $config
     */
    private function makeLocal(array $config): FileDisk
    {
        $root = (string) ($config['root'] ?? sys_get_temp_dir() . '/swoolefy_file_storage');
        $adapter = new LocalAdapter(
            $root,
            isset($config['public_base_url']) ? (string) $config['public_base_url'] : null,
        );

        return new FileDisk(
            store: $adapter,
            metadata: $adapter,
            url: $adapter,
            multipart: $adapter,
            client: null,
            driver: 'local',
        );
    }

    /**
     * 内存假盘：单测用，同一 FakeDisk 挂多 Capability。
     */
    private function makeFake(): FileDisk
    {
        $adapter = new FakeDisk();

        return new FileDisk(
            store: $adapter,
            metadata: $adapter,
            url: $adapter,
            multipart: $adapter,
            client: null,
            driver: 'fake',
        );
    }

    /**
     * AWS S3：适配器同时实现 CloudClientAware，供 getClient() 暴露 S3Client。
     *
     * @param array<string, mixed> $config
     */
    private function makeAwsS3(array $config): FileDisk
    {
        $adapter = new AwsS3Adapter(
            bucket: (string) ($config['bucket'] ?? ''),
            credentials: $this->resolveCredentials($config),
            region: (string) ($config['region'] ?? 'us-east-1'),
            endpoint: isset($config['endpoint']) ? (string) $config['endpoint'] : null,
            usePathStyle: (bool) ($config['use_path_style'] ?? false),
            multipartPartSize: (int) ($config['multipart_part_size'] ?? 8 * 1024 * 1024),
        );

        return new FileDisk(
            store: $adapter,
            metadata: $adapter,
            url: $adapter,
            multipart: $adapter,
            client: $adapter,
            driver: 'aws_s3',
        );
    }

    /**
     * 阿里云 OSS（oss-v2）：同一适配器实例挂接全部 Capability。
     *
     * @param array<string, mixed> $config
     */
    private function makeAliyunOss(array $config): FileDisk
    {
        $adapter = new AliyunOssAdapter(
            bucket: (string) ($config['bucket'] ?? ''),
            credentials: $this->resolveCredentials($config),
            region: (string) ($config['region'] ?? 'cn-hangzhou'),
            endpoint: isset($config['endpoint']) ? (string) $config['endpoint'] : null,
            multipartPartSize: (int) ($config['multipart_part_size'] ?? 8 * 1024 * 1024),
        );

        return new FileDisk(
            store: $adapter,
            metadata: $adapter,
            url: $adapter,
            multipart: $adapter,
            client: $adapter,
            driver: 'aliyun_oss',
        );
    }

    /**
     * 腾讯云 COS：同一适配器实例挂接全部 Capability。
     *
     * @param array<string, mixed> $config
     */
    private function makeTencentCos(array $config): FileDisk
    {
        $adapter = new TencentCosAdapter(
            bucket: (string) ($config['bucket'] ?? ''),
            credentials: $this->resolveCredentials($config),
            region: (string) ($config['region'] ?? 'ap-guangzhou'),
            appId: isset($config['app_id']) ? (string) $config['app_id'] : null,
            endpoint: isset($config['endpoint']) ? (string) $config['endpoint'] : null,
            multipartPartSize: (int) ($config['multipart_part_size'] ?? 8 * 1024 * 1024),
        );

        return new FileDisk(
            store: $adapter,
            metadata: $adapter,
            url: $adapter,
            multipart: $adapter,
            client: $adapter,
            driver: 'tengxun_cos',
        );
    }

    /**
     * 解析 credentials.provider：static 为长期密钥，sts 为可刷新临时凭证（缓存仅在 StsCredentialProvider）。
     *
     * @param array<string, mixed> $config
     */
    private function resolveCredentials(array $config): CredentialProviderInterface
    {
        $cred = $config['credentials'] ?? [];
        if (!is_array($cred)) {
            $cred = [];
        }
        $provider = (string) ($cred['provider'] ?? 'static');

        if ($provider === 'sts') {
            $fetcher = $cred['fetcher'] ?? null;
            if (!is_callable($fetcher)) {
                throw new InvalidProviderException('STS credentials require a callable fetcher');
            }

            return new StsCredentialProvider($fetcher);
        }

        $key = (string) ($cred['key'] ?? $config['key'] ?? $config['access_key'] ?? '');
        $secret = (string) ($cred['secret'] ?? $config['secret'] ?? $config['secret_key'] ?? '');
        $token = isset($cred['token']) ? (string) $cred['token'] : (isset($config['token']) ? (string) $config['token'] : null);

        return new StaticCredentialProvider($key, $secret, $token);
    }
}
