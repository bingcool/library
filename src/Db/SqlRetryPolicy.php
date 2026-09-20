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

namespace Swoolefy\Library\Db;

/**
 * PDO 断线后的 SQL Retry 白名单。
 *
 * 不做 AST / 表达式幂等分析。UPDATE 即使写成 `SET name='x' WHERE id=1` 看起来幂等，
 * 也无法覆盖 `balance = balance - 100`、`NOW()`、`UUID()`、JOIN、LIMIT 等危险形态，
 * 因此当前版本全部 UPDATE 禁止 retry。INSERT 是硬规则：永远不 retry。
 *
 * 分类只做「去前导噪声 + 取第一个关键字」。无法判断时返回 UNKNOWN，默认不 retry。
 * query() 与 execute() 都走同一套文本分类：业务完全可以 execute("SELECT ...")。
 */
final class SqlRetryPolicy
{
    public const TYPE_SELECT = 'SELECT';

    /**
     * SELECT 带行锁或导出子句。连接断开后原事务/锁已不存在，禁止 replay。
     */
    public const TYPE_SELECT_LOCK = 'SELECT_LOCK';

    public const TYPE_SHOW = 'SHOW';
    public const TYPE_DESC = 'DESC';
    public const TYPE_INSERT = 'INSERT';
    public const TYPE_UPDATE = 'UPDATE';
    public const TYPE_DELETE = 'DELETE';
    public const TYPE_REPLACE = 'REPLACE';
    public const TYPE_CALL = 'CALL';
    public const TYPE_DDL = 'DDL';

    /**
     * EXPLAIN ANALYZE 在 PostgreSQL 会真正执行语句，故整类禁止 retry。
     */
    public const TYPE_EXPLAIN = 'EXPLAIN';

    /**
     * WITH 后面可能是 SELECT 也可能是 INSERT/UPDATE。P0 不解析 CTE 主句，一律当 UNKNOWN。
     */
    public const TYPE_WITH = 'WITH';

    public const TYPE_UNKNOWN = 'UNKNOWN';

    /**
     * 仅这三类允许在非事务、连接异常后 replay 一次。
     *
     * @var string[]
     */
    private const RETRYABLE_TYPES = [
        self::TYPE_SELECT,
        self::TYPE_SHOW,
        self::TYPE_DESC,
    ];

    /**
     * SELECT 一旦带锁或导出，语义就不再是「无副作用读」。
     * 使用子串匹配，误判（字符串字面量里碰巧出现 FOR UPDATE）只会少 retry，不会多 replay。
     *
     * @var string[]
     */
    private const SELECT_LOCK_OR_EXPORT_PATTERNS = [
        'FOR UPDATE',
        'FOR SHARE',
        'FOR NO KEY UPDATE',
        'FOR KEY SHARE',
        'LOCK IN SHARE MODE',
        'INTO OUTFILE',
        'INTO DUMPFILE',
    ];

    /**
     * @var string[]
     */
    private const DDL_KEYWORDS = [
        'CREATE', 'ALTER', 'DROP', 'RENAME', 'TRUNCATE',
    ];

    /**
     * @param string $sql 实际将执行的 SQL（拦截器改写之后）
     * @param bool $inTransaction 事务中任何 SQL 都不 retry：新连接上的快照、锁、隔离级别都不是原事务
     */
    public static function isRetryable(string $sql, bool $inTransaction = false): bool
    {
        if ($inTransaction) {
            return false;
        }

        $type = self::classify($sql);

        return in_array($type, self::RETRYABLE_TYPES, true);
    }

    /**
     * 返回 SQL 类型常量，供日志使用（pdo retry skipped: sql_type=UPDATE）。
     *
     * INSERT 无论有无 UNIQUE KEY、是否 INSERT...SELECT / ON DUPLICATE KEY UPDATE，都归为 INSERT。
     * 幂等由业务保证，Library 不推断。
     */
    public static function classify(string $sql): string
    {
        $normalized = self::stripLeadingNoise($sql);
        if ($normalized === '') {
            return self::TYPE_UNKNOWN;
        }

        if (!preg_match('/^([A-Za-z_]+)/', $normalized, $matches)) {
            return self::TYPE_UNKNOWN;
        }

        $keyword = strtoupper($matches[1]);

        return match ($keyword) {
            'SELECT' => self::classifySelect($normalized),
            'SHOW' => self::TYPE_SHOW,
            'DESC', 'DESCRIBE' => self::TYPE_DESC,
            'INSERT' => self::TYPE_INSERT,
            'UPDATE' => self::TYPE_UPDATE,
            'DELETE' => self::TYPE_DELETE,
            'REPLACE' => self::TYPE_REPLACE,
            'CALL', 'EXEC', 'EXECUTE' => self::TYPE_CALL,
            'EXPLAIN' => self::TYPE_EXPLAIN,
            'WITH' => self::TYPE_WITH,
            default => in_array($keyword, self::DDL_KEYWORDS, true) ? self::TYPE_DDL : self::TYPE_UNKNOWN,
        };
    }

    /**
     * 普通 SELECT 可 retry；带锁 / INTO OUTFILE 则视为 SELECT_LOCK。
     */
    private static function classifySelect(string $sql): string
    {
        $upper = strtoupper($sql);
        foreach (self::SELECT_LOCK_OR_EXPORT_PATTERNS as $pattern) {
            if (str_contains($upper, $pattern)) {
                return self::TYPE_SELECT_LOCK;
            }
        }

        return self::TYPE_SELECT;
    }

    /**
     * 去掉前导空白、块注释、行注释、左括号，再取第一个关键字。
     *
     * 兼容块注释、`--` / `#` 行注释、以及 `(SELECT ...)` 包一层括号的写法。
     * 不进入字符串内部扫描，避免做成半套 Parser。
     * $guard 防止畸形注释导致死循环。
     */
    public static function stripLeadingNoise(string $sql): string
    {
        $sql = ltrim($sql);
        $guard = 0;
        while ($sql !== '' && $guard < 32) {
            $guard++;
            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/');
                if ($end === false) {
                    return '';
                }
                $sql = ltrim(substr($sql, $end + 2));
                continue;
            }

            if (str_starts_with($sql, '--') || str_starts_with($sql, '#')) {
                $newLine = strpos($sql, "\n");
                if ($newLine === false) {
                    return '';
                }
                $sql = ltrim(substr($sql, $newLine + 1));
                continue;
            }

            if (str_starts_with($sql, '(')) {
                $sql = ltrim(substr($sql, 1));
                continue;
            }

            break;
        }

        return $sql;
    }
}
