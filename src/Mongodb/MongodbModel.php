<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mongodb;

use MongoDB\Client;
use MongoDB\Database;
use Swoolefy\Library\Mongodb\Config\MongodbConfig;

/**
 * MongoDB 连接入口与 collection 缓存。
 *
 * 每个实例持有独立的 Client 与缓存，同一实例不应跨协程共享；
 * swoolefy 组件 get() 已按协程缓存实例。
 */
class MongodbModel
{
    private readonly MongodbConfig $config;

    private ?Client $client = null;

    /** @var array<string, Database> */
    private array $databases = [];

    /** @var array<string, MongodbCollection> */
    private array $collections = [];

    /**
     * @param array<string, mixed>|MongodbConfig $config
     */
    public function __construct(array|MongodbConfig $config)
    {
        $this->config = $config instanceof MongodbConfig ? $config : new MongodbConfig($config);
    }

    public function client(): Client
    {
        if ($this->client === null) {
            $driverOptions = $this->config->driverOptions();
            $driverOptions['typeMap'] = MongodbConfig::TYPE_MAP;

            $this->client = new Client(
                $this->config->uri(),
                $this->config->uriOptions(),
                $driverOptions
            );
        }

        return $this->client;
    }

    public function database(?string $name = null): Database
    {
        $name ??= $this->config->database();

        return $this->databases[$name] ??= $this->client()->selectDatabase($name);
    }

    public function collection(string $name, ?string $database = null): MongodbCollection
    {
        $database ??= $this->config->database();
        $key = $database . "\0" . $name;

        if (!isset($this->collections[$key])) {
            $this->collections[$key] = new MongodbCollection(
                $this->database($database)->selectCollection($name),
                $this->config->idKey(),
                $this->config->objectId()
            );
        }

        return $this->collections[$key];
    }

    public function ping(): bool
    {
        $result = $this->database()->command(['ping' => 1])->toArray()[0] ?? null;
        if ($result === null) {
            return false;
        }

        $ok = is_array($result) ? ($result['ok'] ?? 0) : ($result->ok ?? 0);

        return (int) $ok === 1;
    }

    public function __get(string $name): MongodbCollection
    {
        return $this->collection($name);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'database' => $this->config->database(),
            'id_key' => $this->config->idKey(),
            'object_id' => $this->config->objectId(),
            'client_created' => $this->client !== null,
            'databases_cached' => array_keys($this->databases),
            'collections_cached' => count($this->collections),
        ];
    }

    public function __destruct()
    {
        $this->client = null;
        $this->databases = [];
        $this->collections = [];
    }
}
