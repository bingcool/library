<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Ns;

use Common\Library\Nacos\Provider\BaseProvider;
use Common\Library\Nacos\Provider\Ns\Model\NamespaceItem;
use Common\Library\Nacos\Http\RequestMethod;

class NamespaceProvider extends BaseProvider
{
    public const NAMESPACE_API_APTH = 'nacos/v1/console/namespaces';

    /**
     * @return NamespaceItem[]
     */
    public function list(): array
    {
        $response = $this->client->request(self::NAMESPACE_API_APTH);
        $result = [];
        foreach ($response->json(true)['data'] as $row) {
            $result[] = new NamespaceItem($row);
        }

        return $result;
    }

    public function create(string $namespaceName = '', string $customNamespaceId = '', string $namespaceDesc = ''): bool
    {
        return 'true' === $this->client->request(self::NAMESPACE_API_APTH, [
            'customNamespaceId' => $customNamespaceId,
            'namespaceName'     => $namespaceName,
            'namespaceDesc'     => $namespaceDesc,
        ], RequestMethod::POST)->body();
    }

    public function update(string $namespaceId, string $namespaceName = '', string $namespaceDesc = ''): bool
    {
        return 'true' === $this->client->request(self::NAMESPACE_API_APTH, [
            'namespace'         => $namespaceId,
            'namespaceShowName' => $namespaceName,
            'namespaceDesc'     => $namespaceDesc,
        ], RequestMethod::PUT)->body();
    }

    public function delete(string $namespaceId): bool
    {
        return 'true' === $this->client->request(self::NAMESPACE_API_APTH, [
            'namespaceId'   => $namespaceId,
        ], RequestMethod::DELETE)->body();
    }
}
