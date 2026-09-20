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

namespace Swoolefy\Library\Redis;

/**
 * Redis 自动重连后的 Retry 白名单策略。
 *
 * 核心原则：Reconnect ≠ Retry。
 * 连接异常可以（也应该）先愈合连接，以便连接池 / 长连接对象可继续被复用；
 * 但「是否把原命令再执行一遍」必须由本 Policy 决定。
 *
 * 为什么用白名单而不是黑名单：
 * Redis 命令会持续增加。若默认允许未知命令 retry，Library 尚未识别的新写命令
 * （例如模块命令 JSON.SET、新版本 GETDEL）会被自动 replay，造成重复副作用。
 *
 * PHPRedis / Predis / RedisCluster 必须共用本类，禁止三套 Driver 出现不同语义。
 */
final class RedisRetryPolicy
{
    /**
     * 额外 replay 次数。1 表示：第一次失败后最多再执行一次。
     */
    public const DEFAULT_MAX_TIMES = 1;

    /**
     * 重连前等待秒数，兼容历史 `__call` 里固定 sleep(0.5) 的行为。
     */
    public const DEFAULT_DELAY = 0.5;

    /**
     * 防止业务把 max_times 配成极大值，在故障期间打爆 Redis。
     */
    public const MAX_TIMES_CAP = 3;

    /**
     * 默认允许 retry 的命令：必须同时满足
     * 1. 只读，不修改数据；
     * 2. 没有可写选项（例如 GEORADIUS 带 STORE 会写 key，故不放入，只放 _RO）；
     * 3. 没有 cursor 语义（SCAN 家族重连后重放同一 cursor 会漏扫 / 重复扫描）。
     *
     * 查找时会去掉非字母数字字符，因此 `GEORADIUS_RO` 与 PHPRedis 方法 `geoRadiusRo`
     * 规范化后都是 `GEORADIUSRO`，视为同一命令。
     *
     * @var string[]
     */
    private const DEFAULT_RETRY_COMMANDS = [
        'GET', 'MGET',
        'STRLEN', 'GETRANGE', 'GETBIT', 'BITCOUNT', 'BITPOS',
        'LCS',
        'EXISTS', 'TYPE', 'TTL', 'PTTL', 'EXPIRETIME', 'PEXPIRETIME',
        'RANDOMKEY', 'DBSIZE', 'KEYS', 'DUMP', 'OBJECT',
        'HGET', 'HMGET', 'HGETALL', 'HEXISTS', 'HLEN', 'HKEYS', 'HVALS', 'HSTRLEN', 'HRANDFIELD',
        'SMEMBERS', 'SCARD', 'SISMEMBER', 'SMISMEMBER',
        'SINTER', 'SINTERCARD', 'SUNION', 'SDIFF', 'SRANDMEMBER',
        'LLEN', 'LINDEX', 'LRANGE',
        'ZCARD', 'ZCOUNT', 'ZRANGE', 'ZRANGEBYSCORE', 'ZREVRANGE', 'ZREVRANGEBYSCORE',
        'ZRANGEBYLEX', 'ZREVRANGEBYLEX', 'ZLEXCOUNT',
        'ZRANK', 'ZREVRANK', 'ZSCORE', 'ZMSCORE', 'ZRANDMEMBER',
        'PFCOUNT',
        'GEODIST', 'GEOHASH', 'GEOPOS',
        'GEORADIUS_RO', 'GEORADIUSBYMEMBER_RO',
        'GEOSEARCH',
        'XLEN', 'XRANGE', 'XREVRANGE', 'XINFO',
        'PING', 'ECHO', 'TIME', 'LASTSAVE', 'MEMORY',
    ];

    /**
     * PHPRedis / RedisCluster 的连接丢失识别串（大小写不敏感）。
     *
     * 不能只看异常类：phpredis 把 WRONGTYPE、NOSCRIPT 等业务错误也打成 RedisException。
     * 若按异常类一律重连 + replay，读错类型的 GET 也会被当成断线重试。
     *
     * @var string[]
     */
    private const CONNECTION_MESSAGE_NEEDLES = [
        'read error on connection',
        'connection lost',
        'redis server went away',
        'socket error',
        'connection closed',
        'no connection to persistent',
        'connection refused',
        'connection timed out',
        'broken pipe',
        'reset by peer',
        'php_network_getaddresses',
        'eof',
        'went away',
        'not connected',
        'connection was reset',
        'server closed the connection',
        'errno',
    ];

    /**
     * @var array{enabled: bool, max_times: int, delay: float, commands: array<string, true>}
     */
    private array $options;

    /**
     * @param array $options retry 配置，见 {@see normalizeOptions()}
     */
    public function __construct(array $options = [])
    {
        $this->options = $this->normalizeOptions($options);
    }

    /**
     * 增量更新配置。未出现的 enabled / max_times / delay 保留当前值。
     *
     * `commands` 若在 $options 中提供非空数组，会替换内置白名单；
     * `extra_commands` 始终追加到最终白名单（用于显式打开 SET 等默认禁止的命令）。
     *
     * @param array $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $this->normalizeOptions($options + [
            'enabled' => $this->options['enabled'],
            'max_times' => $this->options['max_times'],
            'delay' => $this->options['delay'],
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->options['enabled'];
    }

    /**
     * 连接异常后允许的额外 replay 次数，默认 1。
     */
    public function getMaxTimes(): int
    {
        return $this->options['max_times'];
    }

    /**
     * 重连前等待秒数。仅连接异常路径使用，业务错误不得 sleep。
     */
    public function getDelay(): float
    {
        return $this->options['delay'];
    }

    /**
     * 规范化后的白名单查找表，key 为去掉非字母数字后的大写命令名。
     *
     * @return array<string, true>
     */
    public function getCommands(): array
    {
        return $this->options['commands'];
    }

    /**
     * 判断规范化后的命令是否允许 replay。
     *
     * MULTI / PIPELINE / WATCH 进行中必须传 $inTransactionalContext=true：
     * 断线后新连接不在原 MULTI 里，若 replay 其中的 INCR，会变成独立写命令。
     *
     * @param string $command 已通过 {@see resolveCommand()} 或 {@see normalizeCommand()} 的命令名
     * @param bool $inTransactionalContext 当前连接是否处于 MULTI/PIPELINE/WATCH
     */
    public function shouldRetry(string $command, bool $inTransactionalContext = false): bool
    {
        if (!$this->options['enabled'] || $inTransactionalContext) {
            return false;
        }

        $key = self::normalizeCommand($command);
        if ($key === '') {
            return false;
        }

        return isset($this->options['commands'][$key]);
    }

    /**
     * 从 PHP `__call($method, $arguments)` 还原真实 Redis 命令名。
     *
     * - 普通方法：`hGet` / `HGET` / `hget` → `HGET`
     * - `rawCommand('INCR', $key)` / `executeRaw(['GET', $key])`：
     *   必须以第一个参数作为命令。若只看方法名 RAWCOMMAND，写命令会被当成未知命令
     *   （安全，但不精确）；这里解析后写命令可被明确拒绝，读命令可进入白名单。
     *
     * 无法解析时返回空串，调用方应按「未知命令、禁止 retry」处理。
     *
     * @param string $method
     * @param array $arguments
     */
    public static function resolveCommand(string $method, array $arguments): string
    {
        $methodKey = self::normalizeCommand($method);
        if ($methodKey === 'RAWCOMMAND' || $methodKey === 'EXECUTERAW') {
            $first = $arguments[0] ?? null;
            if (is_array($first)) {
                $first = $first[0] ?? '';
            }
            if (!is_string($first) && !is_int($first)) {
                return '';
            }

            return self::normalizeCommand((string)$first);
        }

        return $methodKey;
    }

    /**
     * 命令名规范化：去掉非字母数字后转大写。
     *
     * 目的是抹平 Driver 差异，而不是做 Redis 协议级解析：
     * `geoRadiusRo`、`GEORADIUS_RO`、`georadius-ro` 都会变成 `GEORADIUSRO`。
     */
    public static function normalizeCommand(string $command): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $command));
    }

    /**
     * PHPRedis / RedisCluster 是否属于「连接已断开、执行结果未知」。
     *
     * 必须同时满足：
     * 1. 异常类是 RedisException / RedisClusterException（MOVED/ASK 等若以其它类型抛出则不走本路径）；
     * 2. message 命中连接丢失特征串。
     *
     * WRONGTYPE、NOSCRIPT、ERR unknown command 等业务错误会返回 false，
     * 上层不得 reconnect，更不得 replay。
     */
    public static function isPhpRedisConnectionException(\Throwable $e): bool
    {
        $isRedisException = $e instanceof \RedisException;
        $isClusterException = class_exists(\RedisClusterException::class) && $e instanceof \RedisClusterException;
        if (!$isRedisException && !$isClusterException) {
            return false;
        }

        return self::messageIndicatesConnectionLoss($e->getMessage());
    }

    /**
     * Predis 连接异常判定。
     *
     * Predis 把 Redis 返回的 -ERR 封装成 ServerException（继承自普通 Exception）。
     * 旧实现 catch (\Exception) 会把 WRONGTYPE 也当成断线并 replay，这里必须先排除。
     *
     * 只有 ConnectionException / TimeoutException / CommunicationException 视为连接问题。
     */
    public static function isPredisConnectionException(\Throwable $e): bool
    {
        if (class_exists('Predis\\Response\\ServerException') && $e instanceof \Predis\Response\ServerException) {
            return false;
        }

        if (class_exists('Predis\\Connection\\ConnectionException') && $e instanceof \Predis\Connection\ConnectionException) {
            return true;
        }

        if (class_exists('Predis\\Connection\\TimeoutException') && $e instanceof \Predis\Connection\TimeoutException) {
            return true;
        }

        if (class_exists('Predis\\CommunicationException') && $e instanceof \Predis\CommunicationException) {
            return true;
        }

        return false;
    }

    /**
     * 按子串判断 message 是否像连接丢失。大小写不敏感。
     */
    public static function messageIndicatesConnectionLoss(string $message): bool
    {
        $message = strtolower($message);
        if ($message === '') {
            return false;
        }

        foreach (self::CONNECTION_MESSAGE_NEEDLES as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 规范化并冻结一份可执行配置。
     *
     * 配置语义：
     * - enabled=false：断线仍 reconnect，但任何命令都不 replay
     * - commands 非空：整表替换内置白名单（避免业务只抄 5 个命令后丢失 SMEMBERS）
     * - extra_commands：在最终白名单上追加
     * - max_times 限制在 [0, MAX_TIMES_CAP]
     *
     * @param array<string, mixed> $options
     * @return array{enabled: bool, max_times: int, delay: float, commands: array<string, true>}
     */
    private function normalizeOptions(array $options): array
    {
        $enabled = array_key_exists('enabled', $options) ? (bool)$options['enabled'] : true;
        $maxTimes = (int)($options['max_times'] ?? self::DEFAULT_MAX_TIMES);
        $maxTimes = max(0, min(self::MAX_TIMES_CAP, $maxTimes));
        $delay = (float)($options['delay'] ?? self::DEFAULT_DELAY);
        if ($delay < 0) {
            $delay = 0.0;
        }

        $commands = self::DEFAULT_RETRY_COMMANDS;
        if (isset($options['commands']) && is_array($options['commands']) && $options['commands'] !== []) {
            $commands = $options['commands'];
        }

        $lookup = [];
        foreach ($commands as $command) {
            if (!is_string($command) || $command === '') {
                continue;
            }
            $key = self::normalizeCommand($command);
            if ($key !== '') {
                $lookup[$key] = true;
            }
        }

        if (isset($options['extra_commands']) && is_array($options['extra_commands'])) {
            foreach ($options['extra_commands'] as $command) {
                if (!is_string($command) || $command === '') {
                    continue;
                }
                $key = self::normalizeCommand($command);
                if ($key !== '') {
                    $lookup[$key] = true;
                }
            }
        }

        return [
            'enabled' => $enabled,
            'max_times' => $maxTimes,
            'delay' => $delay,
            'commands' => $lookup,
        ];
    }
}
