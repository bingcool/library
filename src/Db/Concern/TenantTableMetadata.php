<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\Db\Concern;

use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Library\Db\Interceptor\TenantLineHandlerInterface;

/**
 * 租户隔离共用的表字段元信息缓存，供 TenantScope 与 TenantLineInterceptor 复用。
 */
class TenantTableMetadata
{
    /**
     * 当前协程中缓存表字段元信息的 key。
     */
    public const CONTEXT_TABLE_FIELDS_CACHE_KEY = '__tenant_line_table_fields_cache';

    /**
     * 非协程环境下的表字段元信息缓存。
     *
     * @var array<string, array>
     */
    private static array $tableFieldsCache = [];

    /**
     * 判断数据表是否包含租户字段（Model Scope 使用）。
     */
    public static function tableHasTenantColumn(object $connection, string $table, TenantLineHandlerInterface $handler): bool
    {
        $table = self::normalizeTableName($table);
        if ($table === '') {
            return false;
        }

        $fields = self::getFields($connection, $table);
        if ($fields === []) {
            return !$handler->ignoreTable($table);
        }

        return self::hasTenantColumn($fields, $handler->getTenantIdColumn());
    }

    /**
     * 判断数据表是否需要跳过租户改写（SQL 拦截器使用）。
     */
    public static function shouldIgnore(object $connection, string $table, TenantLineHandlerInterface $handler): bool
    {
        $table = self::normalizeTableName($table);
        if ($table === '') {
            return true;
        }

        $fields = self::getFields($connection, $table);
        if ($fields !== []) {
            return !self::hasTenantColumn($fields, $handler->getTenantIdColumn());
        }

        return $handler->ignoreTable($table);
    }

    /**
     * 从当前连接读取表字段元信息（带协程/进程内缓存）。
     *
     * @return array 可读取时返回以字段名为 key 的字段元信息；失败时返回空数组并缓存。
     */
    public static function getFields(object $connection, string $table): array
    {
        $table = self::normalizeTableName($table);
        if ($table === '') {
            return [];
        }

        $connection = self::resolveConnection($connection);
        $cacheKey = self::buildTableFieldsCacheKey($connection, $table);

        if (self::hasCachedTableFields($cacheKey)) {
            return self::getCachedTableFields($cacheKey);
        }

        try {
            $fields = $connection->getFields($table);
        } catch (\Throwable $exception) {
            $fields = [];
        }

        self::setCachedTableFields($cacheKey, $fields);

        return $fields;
    }

    /**
     * 检查表字段元信息中是否包含配置的租户字段。
     */
    public static function hasTenantColumn(array $fields, string $tenantColumn): bool
    {
        $tenantColumn = self::cleanIdentifier($tenantColumn);

        if (array_key_exists($tenantColumn, $fields)) {
            return true;
        }

        foreach ($fields as $field => $info) {
            if (self::cleanIdentifier((string) $field) === $tenantColumn) {
                return true;
            }

            if (is_array($info) && isset($info['name']) && self::cleanIdentifier((string) $info['name']) === $tenantColumn) {
                return true;
            }
        }

        return false;
    }

    /**
     * 标准化表名：去引号，并去掉 schema/database 前缀。
     */
    public static function normalizeTableName(string $table): string
    {
        $table = self::cleanIdentifier($table);

        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }

    /**
     * 移除常见 SQL 标识符引号字符。
     */
    public static function cleanIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        $identifier = trim($identifier, '`"[]');

        return str_replace(['`', '"', '[', ']'], '', $identifier);
    }

    /**
     * 清空表字段缓存（测试或热更新表结构时使用）。
     */
    public static function clearCache(): void
    {
        if (self::isCoroutineContextAvailable()) {
            SwooleContext::set(self::CONTEXT_TABLE_FIELDS_CACHE_KEY, []);
        }

        self::$tableFieldsCache = [];
    }

    /**
     * @return object 实际执行 getFields 的连接对象。
     */
    private static function resolveConnection(object $connection): object
    {
        if (method_exists($connection, 'getObject')) {
            $connection = $connection->getObject();
        }

        return $connection;
    }

    private static function buildTableFieldsCacheKey(object $connection, string $table): string
    {
        return spl_object_id($connection) . ':' . $table;
    }

    private static function hasCachedTableFields(string $cacheKey): bool
    {
        $cache = self::getTableFieldsCache();

        return array_key_exists($cacheKey, $cache);
    }

    private static function getCachedTableFields(string $cacheKey): array
    {
        $cache = self::getTableFieldsCache();

        return $cache[$cacheKey] ?? [];
    }

    private static function setCachedTableFields(string $cacheKey, array $fields): void
    {
        $cache = self::getTableFieldsCache();
        $cache[$cacheKey] = $fields;
        self::setTableFieldsCache($cache);
    }

    private static function getTableFieldsCache(): array
    {
        if (self::isCoroutineContextAvailable()) {
            if (!SwooleContext::has(self::CONTEXT_TABLE_FIELDS_CACHE_KEY)) {
                SwooleContext::set(self::CONTEXT_TABLE_FIELDS_CACHE_KEY, []);
            }

            $cache = SwooleContext::get(self::CONTEXT_TABLE_FIELDS_CACHE_KEY);
            if (!is_array($cache)) {
                $cache = [];
                SwooleContext::set(self::CONTEXT_TABLE_FIELDS_CACHE_KEY, $cache);
            }

            return $cache;
        }

        return self::$tableFieldsCache;
    }

    private static function setTableFieldsCache(array $cache): void
    {
        if (self::isCoroutineContextAvailable()) {
            SwooleContext::set(self::CONTEXT_TABLE_FIELDS_CACHE_KEY, $cache);

            return;
        }

        self::$tableFieldsCache = $cache;
    }

    private static function isCoroutineContextAvailable(): bool
    {
        return class_exists(SwooleContext::class)
            && \Swoole\Coroutine::getCid() >= 0;
    }
}
