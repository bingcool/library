<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Provider\Instance;

use Swoolefy\Library\Nacos\Provider\BaseProvider;
use Swoolefy\Library\Nacos\Provider\Instance\Model\BeatResponse;
use Swoolefy\Library\Nacos\Provider\Instance\Model\DetailResponse;
use Swoolefy\Library\Nacos\Provider\Instance\Model\ListResponse;
use Swoolefy\Library\Nacos\Provider\Instance\Model\RsInfo;
use Swoolefy\Library\Nacos\Util\StringUtil;
use Swoolefy\Library\Nacos\Http\RequestMethod;

class InstanceProvider extends BaseProvider
{
    public const INSTANCE_API_APTH = 'nacos/v1/ns/instance';

    /**
     * @param string|float $weight
     */
    public function register(string $ip, int $port, string $serviceName, string $namespaceId = '', $weight = 1, bool $enabled = true, bool $healthy = true, string $metadata = '', string $clusterName = '', string $groupName = '', bool $ephemeral = true): bool
    {
        return 'ok' === $this->client->request(self::INSTANCE_API_APTH, [
            'ip'          => $ip,
            'port'        => $port,
            'namespaceId' => $namespaceId,
            'weight'      => $weight,
            'enabled'     => StringUtil::convertBoolToString($enabled),
            'healthy'     => StringUtil::convertBoolToString($healthy),
            'metadata'    => $metadata,
            'clusterName' => $clusterName,
            'serviceName' => $serviceName,
            'groupName'   => $groupName,
            'ephemeral'   => StringUtil::convertBoolToString($ephemeral),
        ], RequestMethod::POST)->body();
    }

    public function deregister(string $ip, int $port, string $serviceName, string $namespaceId = '', string $clusterName = '', string $groupName = '', bool $ephemeral = true): bool
    {
        return 'ok' === $this->client->request(self::INSTANCE_API_APTH, [
            'ip'          => $ip,
            'port'        => $port,
            'namespaceId' => $namespaceId,
            'clusterName' => $clusterName,
            'serviceName' => $serviceName,
            'groupName'   => $groupName,
            'ephemeral'   => StringUtil::convertBoolToString($ephemeral),
        ], RequestMethod::DELETE)->body();
    }

    /**
     * @param string|float $weight
     */
    public function update(string $ip, int $port, string $serviceName, string $namespaceId = '', $weight = 1, bool $enabled = true, bool $healthy = true, string $metadata = '', string $clusterName = '', string $groupName = '', bool $ephemeral = true): bool
    {
        return 'ok' === $this->client->request(self::INSTANCE_API_APTH, [
            'ip'          => $ip,
            'port'        => $port,
            'namespaceId' => $namespaceId,
            'weight'      => $weight,
            'enabled'     => StringUtil::convertBoolToString($enabled),
            'healthy'     => StringUtil::convertBoolToString($healthy),
            'metadata'    => $metadata,
            'clusterName' => $clusterName,
            'serviceName' => $serviceName,
            'groupName'   => $groupName,
            'ephemeral'   => StringUtil::convertBoolToString($ephemeral),
        ], RequestMethod::PUT)->body();
    }

    /**
     * @param string|string[] $clusters
     */
    public function list(string $serviceName, string $groupName = '', string $namespaceId = '', $clusters = '', bool $healthyOnly = false): ListResponse
    {
        return $this->client->request('nacos/v1/ns/instance/list', [
            'serviceName' => $serviceName,
            'groupName'   => $groupName,
            'namespaceId' => $namespaceId,
            'clusters'    => \is_array($clusters) ? implode(',', $clusters) : $clusters,
            'healthyOnly' => StringUtil::convertBoolToString($healthyOnly),
        ], RequestMethod::GET, [], ListResponse::class);
    }

    /**
     * @param string|string[] $clusters
     */
    public function detail(string $ip, int $port, string $serviceName, string $groupName = '', string $namespaceId = '', $clusters = '', bool $healthyOnly = false, bool $ephemeral = true): DetailResponse
    {
        return $this->client->request(self::INSTANCE_API_APTH, [
            'ip'          => $ip,
            'port'        => $port,
            'serviceName' => $serviceName,
            'groupName'   => $groupName,
            'namespaceId' => $namespaceId,
            'clusters'    => \is_array($clusters) ? implode(',', $clusters) : $clusters,
            'healthyOnly' => StringUtil::convertBoolToString($healthyOnly),
            'ephemeral'   => StringUtil::convertBoolToString($ephemeral),
        ], RequestMethod::GET, [], DetailResponse::class);
    }

    /** 重型心跳：携带 beat JSON，用于首次注册或服务端尚未启用轻量模式时 */
    public function beat(string $serviceName, RsInfo $beat, string $groupName = '', string $namespaceId = '', bool $ephemeral = true): BeatResponse
    {
        return $this->client->request('nacos/v1/ns/instance/beat', [
            'serviceName' => $serviceName,
            'ip'          => $beat->getIp(),
            'port'        => $beat->getPort(),
            'namespaceId' => $namespaceId,
            'beat'        => json_encode($beat),
            'groupName'   => $groupName,
            'ephemeral'   => StringUtil::convertBoolToString($ephemeral),
        ], RequestMethod::PUT, [], BeatResponse::class);
    }

    /**
     * 轻量心跳：响应含 lightBeatEnabled=true 后必须使用此方法续约。
     * 若仍携带 beat 参数，Nacos 约 15s 后会标记不健康并剔除实例。
     */
    public function lightBeat(
        string $serviceName,
        string $ip,
        int $port,
        string $groupName = '',
        string $namespaceId = '',
        bool $ephemeral = true,
    ): BeatResponse {
        return $this->client->request('nacos/v1/ns/instance/beat', [
            'serviceName' => $serviceName,
            'ip'          => $ip,
            'port'        => $port,
            'namespaceId' => $namespaceId,
            'groupName'   => $groupName,
            'ephemeral'   => StringUtil::convertBoolToString($ephemeral),
        ], RequestMethod::PUT, [], BeatResponse::class);
    }
}
