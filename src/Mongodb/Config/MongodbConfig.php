<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mongodb\Config;

use InvalidArgumentException;

/**
 * MongodbModel 配置解析与校验。
 *
 * 构造阶段完成全部校验，不建立连接；异常信息不包含完整 URI。
 */
final class MongodbConfig
{
    public const TYPE_MAP = [
        'root' => 'array',
        'document' => 'array',
        'array' => 'array',
    ];

    private const KNOWN_KEYS = [
        'uri',
        'database',
        'uri_options',
        'driver_options',
        'id_key',
        'object_id',
    ];

    private const DEFAULT_URI_OPTIONS = [
        'serverSelectionTimeoutMS' => 3000,
        'connectTimeoutMS' => 2000,
        'socketTimeoutMS' => 30000,
    ];

    private string $uri;

    private string $database;

    /** @var array<string, mixed> */
    private array $uriOptions;

    /** @var array<string, mixed> */
    private array $driverOptions;

    private string $idKey;

    private bool $objectId;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $unknownKeys = array_diff(array_keys($config), self::KNOWN_KEYS);
        if ($unknownKeys !== []) {
            throw new InvalidArgumentException(
                'Unknown Mongodb config key(s): ' . implode(', ', array_map('strval', $unknownKeys))
            );
        }

        $this->uri = $this->parseUri($config['uri'] ?? null);
        $this->database = $this->parseDatabase($config['database'] ?? null);
        $this->uriOptions = $this->parseUriOptions($config);
        $this->driverOptions = $this->parseDriverOptions($config);
        $this->idKey = $this->parseIdKey($config['id_key'] ?? '_id');
        $this->objectId = $this->parseObjectId($config['object_id'] ?? true);
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function database(): string
    {
        return $this->database;
    }

    /**
     * @return array<string, mixed>
     */
    public function uriOptions(): array
    {
        return $this->uriOptions;
    }

    /**
     * @return array<string, mixed>
     */
    public function driverOptions(): array
    {
        return $this->driverOptions;
    }

    public function idKey(): string
    {
        return $this->idKey;
    }

    public function objectId(): bool
    {
        return $this->objectId;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'uri' => '[redacted]',
            'database' => $this->database,
            'uri_options' => array_keys($this->uriOptions),
            'driver_options' => array_keys($this->driverOptions),
            'id_key' => $this->idKey,
            'object_id' => $this->objectId,
        ];
    }

    private function parseUri(mixed $uri): string
    {
        if (!is_string($uri) || $uri === '') {
            throw new InvalidArgumentException('Mongodb config "uri" is required and must be a non-empty string.');
        }

        $scheme = strtolower((string) strstr($uri, '://', true));
        if ($scheme !== 'mongodb' && $scheme !== 'mongodb+srv') {
            throw new InvalidArgumentException(
                'Mongodb config "uri" must start with "mongodb://" or "mongodb+srv://".'
            );
        }

        return $uri;
    }

    private function parseDatabase(mixed $database): string
    {
        if (!is_string($database) || $database === '') {
            throw new InvalidArgumentException(
                'Mongodb config "database" is required and must be a non-empty string.'
            );
        }

        if (strlen($database) > 64) {
            throw new InvalidArgumentException('Mongodb config "database" must not exceed 64 bytes.');
        }

        if (preg_match('~[\\\\/. "$*<>:|?\x00]~', $database) === 1) {
            throw new InvalidArgumentException(
                'Mongodb config "database" contains characters that are not allowed in a database name.'
            );
        }

        return $database;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function parseUriOptions(array $config): array
    {
        if (!array_key_exists('uri_options', $config)) {
            return self::DEFAULT_URI_OPTIONS;
        }

        if (!is_array($config['uri_options'])) {
            throw new InvalidArgumentException('Mongodb config "uri_options" must be an array.');
        }

        return $config['uri_options'] + self::DEFAULT_URI_OPTIONS;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function parseDriverOptions(array $config): array
    {
        if (!array_key_exists('driver_options', $config)) {
            return [];
        }

        if (!is_array($config['driver_options'])) {
            throw new InvalidArgumentException('Mongodb config "driver_options" must be an array.');
        }

        foreach (['typeMap', 'codec'] as $key) {
            if (array_key_exists($key, $config['driver_options'])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Mongodb config "driver_options" must not contain "%s"; the module enforces array '
                        . 'decoding. Use raw() for custom decoding.',
                        $key
                    )
                );
            }
        }

        return $config['driver_options'];
    }

    private function parseIdKey(mixed $idKey): string
    {
        if (!is_string($idKey) || $idKey === '') {
            throw new InvalidArgumentException('Mongodb config "id_key" must be a non-empty string.');
        }

        if (str_contains($idKey, '.') || str_starts_with($idKey, '$') || str_contains($idKey, "\0")) {
            throw new InvalidArgumentException(
                'Mongodb config "id_key" must not contain ".", start with "$" or contain null bytes.'
            );
        }

        return $idKey;
    }

    private function parseObjectId(mixed $objectId): bool
    {
        if (!is_bool($objectId)) {
            throw new InvalidArgumentException('Mongodb config "object_id" must be a boolean.');
        }

        return $objectId;
    }
}
