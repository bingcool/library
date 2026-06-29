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

namespace Swoolefy\Library\Db\Interceptor;

use PDO;
use Swoolefy\Library\Db\PDOConnection;
use Swoolefy\Core\Coroutine\Context as SwooleContext;

/**
 * 自动注入租户隔离条件的SQL拦截器。
 *
 * 该类参考MyBatis-Plus TenantLineInnerInterceptor的思路：SQL发送给PDO之前，
 * 先识别SQL类型和目标表，再按当前租户ID改写SQL，保证带租户字段的数据表自动
 * 增加租户隔离条件。
 *
 * 支持的SQL类型：
 * - SELECT：在WHERE中追加租户条件。
 * - UPDATE：在WHERE中追加租户条件。
 * - DELETE：在WHERE中追加租户条件。
 * - INSERT / REPLACE：缺少租户字段时自动补充租户字段和值。
 *
 * 是否需要租户隔离优先由表字段元信息决定。拦截器会调用
 * PDOConnection::getFields($table)，检查表中是否存在配置的租户字段。存在租户字段
 * 的表会参与隔离；不存在租户字段的表会自动忽略。只有无法读取字段时，才会回退调用
 * TenantLineHandlerInterface::ignoreTable()。
 *
 * 租户ID始终放入PDO绑定参数中，SQL里只追加占位符，避免把运行时租户值直接拼接到
 * SQL字符串中。
 *
 * 限制说明：这是面向常见Builder生成SQL和简单原生SQL的轻量改写器。复杂SQL例如
 * 嵌套子查询、多个租户表JOIN、CTE或数据库厂商特殊语法，可能需要业务显式添加条件
 * 或接入专门的SQL解析器。
 */
class TenantLineInterceptor implements SqlInterceptorInterface
{
    /**
     * 当前协程中缓存表字段元信息的key。
     */
    protected const CONTEXT_TABLE_FIELDS_CACHE_KEY = '__tenant_line_table_fields_cache';

    /**
     * 提供租户ID、租户字段名和兜底忽略判断。
     *
     * @var TenantLineHandlerInterface
     */
    protected $handler;

    /**
     * INSERT / REPLACE是否自动填充租户ID。
     *
     * 为false时，SELECT / UPDATE / DELETE仍然会追加租户过滤条件，
     * 但插入语句需要业务自行提供租户字段。
     *
     * @var bool
     */
    protected $insertFill;

    /**
     * 非协程环境下的表字段元信息缓存。
     *
     * 协程环境优先使用SwooleContext，普通CLI或非协程PHP环境使用该属性兜底。
     *
     * @var array
     */
    protected $tableFieldsCache = [];

    /**
     * @param TenantLineHandlerInterface $handler 租户元信息处理器。
     * @param bool $insertFill INSERT / REPLACE时是否自动填充租户字段。
     */
    public function __construct(TenantLineHandlerInterface $handler, bool $insertFill = true)
    {
        $this->handler = $handler;
        $this->insertFill = $insertFill;
    }

    /**
     * PDOConnection预处理SQL前调用的入口方法。
     *
     * 如果当前租户ID为空，则SQL保持不变。否则先识别SQL类型，再分发到对应的
     * SQL改写方法。
     *
     * SQL和绑定参数都会通过引用修改，确保每一个新增占位符都有对应的PDO绑定值。
     *
     * @param PDOConnection $connection
     * @param string $sql 需要改写的SQL。
     * @param array $bindParams SQL已有的绑定参数。
     * @return void
     */
    public function beforeExecute(PDOConnection $connection, string &$sql, array &$bindParams): void
    {
        $tenantId = $this->handler->getTenantId();
        if (is_null($tenantId) || $tenantId === '') {
            return;
        }

        $statement = $this->getStatementType($sql);
        if (!$statement) {
            return;
        }

        switch ($statement) {
            case 'SELECT':
                $this->rewriteSelect($connection, $sql, $bindParams, $tenantId);
                break;
            case 'UPDATE':
                $this->rewriteUpdate($connection, $sql, $bindParams, $tenantId);
                break;
            case 'DELETE':
                $this->rewriteDelete($connection, $sql, $bindParams, $tenantId);
                break;
            case 'INSERT':
            case 'REPLACE':
                if ($this->insertFill) {
                    $this->rewriteInsert($connection, $sql, $bindParams, $tenantId);
                }
                break;
        }
    }

    /**
     * 识别SQL开头的语句类型。
     *
     * 只返回当前拦截器支持的语句类型。SHOW、DESCRIBE、ALTER、CREATE、BEGIN、
     * COMMIT以及数据库厂商特殊命令会返回空字符串，不做改写。
     *
     * @param string $sql 原始SQL。
     * @return string 大写语句类型，无法识别时返回空字符串。
     */
    protected function getStatementType(string $sql): string
    {
        if (preg_match('/^\s*(SELECT|UPDATE|DELETE|INSERT|REPLACE)\b/i', $sql, $matches)) {
            return strtoupper($matches[1]);
        }

        return '';
    }

    /**
     * 改写SELECT语句，追加租户过滤条件。
     *
     * 从第一个FROM子句读取目标表。如果该表包含租户字段，则在GROUP BY、HAVING、
     * UNION、ORDER BY、LIMIT、锁定子句等尾部子句之前插入租户条件。
     * 已有WHERE表达式会先用括号包裹，再通过AND追加租户条件。
     *
     * @param PDOConnection $connection
     * @param string $sql 需要改写的SQL。
     * @param array $bindParams 用于追加租户占位符的绑定参数。
     * @param mixed $tenantId 当前租户ID。
     * @return void
     */
    protected function rewriteSelect(PDOConnection $connection, string &$sql, array &$bindParams, $tenantId): void
    {
        $tableInfo = $this->matchTableAfterKeyword($sql, 'FROM');
        if (!$tableInfo || $this->shouldIgnore($connection, $tableInfo['table'])) {
            return;
        }

        $condition = $this->buildTenantCondition($tableInfo['alias'], $bindParams, $tenantId);
        $this->appendWhereCondition($sql, $condition, ['GROUP BY', 'HAVING', 'UNION', 'ORDER BY', 'LIMIT', 'OFFSET', 'FOR UPDATE', 'LOCK IN SHARE MODE']);
    }

    /**
     * 改写UPDATE语句，追加租户过滤条件。
     *
     * 支持常见的UPDATE table SET ...语法和简单JOIN UPDATE前缀。如果存在表别名，
     * 会使用别名限定租户字段，避免字段歧义。
     *
     * @param PDOConnection $connection
     * @param string $sql 需要改写的SQL。
     * @param array $bindParams 用于追加租户占位符的绑定参数。
     * @param mixed $tenantId 当前租户ID。
     * @return void
     */
    protected function rewriteUpdate(PDOConnection $connection, string &$sql, array &$bindParams, $tenantId): void
    {
        if (!preg_match('/^\s*UPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)?(`[^`]+`|"[^"]+"|\[[^\]]+\]|[\w.]+)(?:\s+(?:AS\s+)?(`?[a-zA-Z_][\w]*`?|"[a-zA-Z_][\w]*"|\[[a-zA-Z_][\w]*\]))?\s+(?:SET|JOIN|INNER|LEFT|RIGHT|FULL|CROSS)\b/i', $sql, $matches)) {
            return;
        }

        $table = $this->cleanIdentifier($matches[1]);
        if ($this->shouldIgnore($connection, $table)) {
            return;
        }

        $alias = $this->normalizeAlias($matches[2] ?? '');
        $condition = $this->buildTenantCondition($alias, $bindParams, $tenantId);
        $this->appendWhereCondition($sql, $condition, ['ORDER BY', 'LIMIT']);
    }

    /**
     * 改写DELETE语句，追加租户过滤条件。
     *
     * 从FROM子句读取目标表。已有WHERE条件会被保留并用括号包裹，再追加租户条件。
     *
     * @param PDOConnection $connection
     * @param string $sql 需要改写的SQL。
     * @param array $bindParams 用于追加租户占位符的绑定参数。
     * @param mixed $tenantId 当前租户ID。
     * @return void
     */
    protected function rewriteDelete(PDOConnection $connection, string &$sql, array &$bindParams, $tenantId): void
    {
        $tableInfo = $this->matchTableAfterKeyword($sql, 'FROM');
        if (!$tableInfo || $this->shouldIgnore($connection, $tableInfo['table'])) {
            return;
        }

        $condition = $this->buildTenantCondition($tableInfo['alias'], $bindParams, $tenantId);
        $this->appendWhereCondition($sql, $condition, ['ORDER BY', 'LIMIT']);
    }

    /**
     * 改写INSERT / REPLACE语句，自动填充租户字段。
     *
     * 如果字段列表或SET列表中已经包含租户字段，则保持SQL不变。否则VALUES语法会给
     * 每一行追加租户占位符，SET语法会在SET列表末尾追加租户字段赋值。
     *
     * @param PDOConnection $connection
     * @param string $sql 需要改写的SQL。
     * @param array $bindParams 用于追加租户占位符的绑定参数。
     * @param mixed $tenantId 当前租户ID。
     * @return void
     */
    protected function rewriteInsert(PDOConnection $connection, string &$sql, array &$bindParams, $tenantId): void
    {
        $tableInfo = $this->matchTableAfterKeyword($sql, 'INTO');
        if (!$tableInfo || $this->shouldIgnore($connection, $tableInfo['table'])) {
            return;
        }

        $column = $this->handler->getTenantIdColumn();
        if ($this->hasInsertColumn($sql, $column)) {
            return;
        }

        if ($this->rewriteInsertSet($sql, $bindParams, $tenantId)) {
            return;
        }

        $this->rewriteInsertValues($sql, $bindParams, $tenantId);
    }

    /**
     * 从指定SQL关键字后提取表名和可选别名。
     *
     * 示例：
     * - FROM users u       => table: users, alias: u
     * - FROM `users` AS u  => table: users, alias: u
     * - INTO users         => table: users, alias: ''
     *
     * 如果表名后的token是SQL关键字，则不会当作别名处理。返回的表名会去除引号字符。
     *
     * @param string $sql 待解析的SQL。
     * @param string $keyword 目标表名前的关键字，例如FROM或INTO。
     * @return array{table:string,alias:string}|array 未找到表名时返回空数组。
     */
    protected function matchTableAfterKeyword(string $sql, string $keyword): array
    {
        $pattern = '/\b' . preg_quote($keyword, '/') . '\s+(`[^`]+`|"[^"]+"|\[[^\]]+\]|[\w.]+)(?:\s+(?:AS\s+)?(`?[a-zA-Z_][\w]*`?|"[a-zA-Z_][\w]*"|\[[a-zA-Z_][\w]*\]))?/i';
        if (!preg_match($pattern, $sql, $matches)) {
            return [];
        }

        $alias = $this->normalizeAlias($matches[2] ?? '');
        if ($this->isKeyword($alias)) {
            $alias = '';
        }

        return [
            'table' => $this->cleanIdentifier($matches[1]),
            'alias' => $alias,
        ];
    }

    /**
     * 向WHERE子句追加租户条件。
     *
     * 如果SQL已有WHERE，会将原条件包裹为：
     * WHERE ( 原始条件 ) AND 租户条件
     *
     * 如果SQL没有WHERE，会在第一个尾部关键字之前插入新的WHERE子句，例如ORDER BY
     * 或LIMIT之前，保证改写后的SQL子句顺序仍然合法。
     *
     * @param string $sql 需要修改的SQL。
     * @param string $condition 租户条件SQL片段。
     * @param array $tailKeywords 必须保留在WHERE之后的尾部子句。
     * @return void
     */
    protected function appendWhereCondition(string &$sql, string $condition, array $tailKeywords): void
    {
        $tailOffset = $this->findTailOffset($sql, $tailKeywords);
        $head = false === $tailOffset ? $sql : substr($sql, 0, $tailOffset);
        $tail = false === $tailOffset ? '' : substr($sql, $tailOffset);

        if (preg_match('/\sWHERE\s/i', $head, $matches, PREG_OFFSET_CAPTURE)) {
            $whereOffset = $matches[0][1];
            $whereLength = strlen($matches[0][0]);
            $beforeWhere = substr($head, 0, $whereOffset);
            $where = trim(substr($head, $whereOffset + $whereLength));
            $sql = rtrim($beforeWhere) . ' WHERE ( ' . $where . ' ) AND ' . $condition . $tail;
        } else {
            $sql = rtrim($head) . ' WHERE ' . $condition . $tail;
        }
    }

    /**
     * 查找WHERE子句应该插入到尾部子句之前的位置。
     *
     * 返回第一个命中的关键字偏移量。调用方据此把SQL拆成头部和尾部，再把租户条件
     * 插入两者之间。
     *
     * @param string $sql 待检查的SQL。
     * @param array $keywords 候选尾部子句。
     * @return int|false
     */
    protected function findTailOffset(string $sql, array $keywords)
    {
        $pattern = '/\s+(' . implode('|', array_map(function ($keyword) {
            return preg_replace('/\s+/', '\\s+', preg_quote($keyword, '/'));
        }, $keywords)) . ')\b/i';

        if (preg_match($pattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            return $matches[0][1];
        }

        return false;
    }

    /**
     * 为MySQL INSERT ... SET语法填充租户ID。
     *
     * 示例：
     * INSERT INTO orders SET amount = :amount
     * 改写为：
     * INSERT INTO orders SET amount = :amount, `tenant_id` = :__tenant_id_x
     *
     * @param string $sql 需要修改的SQL。
     * @param array $bindParams 用于追加租户占位符的绑定参数。
     * @param mixed $tenantId 当前租户ID。
     * @return bool SQL被改写时返回true。
     */
    protected function rewriteInsertSet(string &$sql, array &$bindParams, $tenantId): bool
    {
        if (!preg_match('/\bSET\b/i', $sql, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $tailOffset = $this->findTailOffset($sql, ['ON DUPLICATE KEY UPDATE']);
        $head = false === $tailOffset ? $sql : substr($sql, 0, $tailOffset);
        $tail = false === $tailOffset ? '' : substr($sql, $tailOffset);
        $placeholder = $this->bindTenantId($bindParams, $tenantId);
        $sql = rtrim($head) . ' , ' . $this->parseColumn() . ' = ' . $placeholder . $tail;

        return true;
    }

    /**
     * 为INSERT ... (fields) VALUES (...)语法填充租户ID。
     *
     * 多行插入时，每一行都会生成独立的租户占位符，避免绑定参数名冲突。
     *
     * @param string $sql 需要修改的SQL。
     * @param array $bindParams 用于追加租户占位符的绑定参数。
     * @param mixed $tenantId 当前租户ID。
     * @return bool SQL被改写时返回true。
     */
    protected function rewriteInsertValues(string &$sql, array &$bindParams, $tenantId): bool
    {
        if (!preg_match('/^(\s*(?:INSERT|REPLACE)\b.*?\bINTO\s+[`"\[\]\w.]+\s*)\((.*?)\)(\s*VALUES\s*)(.*)$/is', $sql, $matches)) {
            return false;
        }

        $valuesAndTail = $this->splitValuesAndTail($matches[4]);
        if (empty($valuesAndTail['values'])) {
            return false;
        }

        $rows = $this->splitInsertRows($valuesAndTail['values']);
        if (empty($rows)) {
            return false;
        }

        $newRows = [];
        foreach ($rows as $row) {
            $placeholder = $this->bindTenantId($bindParams, $tenantId);
            $newRows[] = rtrim(substr(trim($row), 0, -1)) . ', ' . $placeholder . ' )';
        }

        $fields = rtrim($matches[2]) . ', ' . $this->parseColumn();
        $sql = $matches[1] . '(' . $fields . ')' . $matches[3] . implode(' , ', $newRows) . $valuesAndTail['tail'];

        return true;
    }

    /**
     * 将INSERT的VALUES部分和ON DUPLICATE KEY UPDATE尾部拆开。
     *
     * 插入租户占位符后，重复键更新尾部仍然必须保留在所有VALUES行之后。
     *
     * @param string $values VALUES部分以及可选的重复键更新尾部。
     * @return array{values:string,tail:string}
     */
    protected function splitValuesAndTail(string $values): array
    {
        if (preg_match('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+/i', $values, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = $matches[0][1];
            return [
                'values' => substr($values, 0, $offset),
                'tail' => substr($values, $offset),
            ];
        }

        return [
            'values' => $values,
            'tail' => '',
        ];
    }

    /**
     * 将VALUES列表拆分为单独的括号行。
     *
     * 这个轻量扫描器会跟踪括号和引号，避免值、函数调用或字符串中的逗号被误判为
     * 行分隔符。
     *
     * @param string $values 原始VALUES片段。
     * @return array 行片段列表，每一项都包含外层括号。
     */
    protected function splitInsertRows(string $values): array
    {
        $rows = [];
        $length = strlen($values);
        $depth = 0;
        $start = null;
        $quote = '';
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $values[$i];

            if ($quote) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $rows[] = substr($values, $start, $i - $start + 1);
                    $start = null;
                }
            }
        }

        return $rows;
    }

    /**
     * 检查INSERT语句是否已经提供租户字段。
     *
     * 当业务代码已经显式填充tenant_id时，可避免重复追加租户字段。
     *
     * @param string $sql INSERT / REPLACE SQL。
     * @param string $column 租户字段名。
     * @return bool 已存在租户字段时返回true。
     */
    protected function hasInsertColumn(string $sql, string $column): bool
    {
        $column = preg_quote($this->cleanIdentifier($column), '/');

        if (preg_match('/\((.*?)\)\s*VALUES\s*/is', $sql, $matches)) {
            return (bool)preg_match('/(^|,)\s*`?' . $column . '`?\s*(,|$)/i', $matches[1]);
        }

        if (preg_match('/\bSET\b(.*?)(?:\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+|$)/is', $sql, $matches)) {
            return (bool)preg_match('/(^|,)\s*`?' . $column . '`?\s*=/i', $matches[1]);
        }

        return false;
    }

    /**
     * 构建租户过滤表达式并绑定租户ID。
     *
     * 如果存在表别名，会使用别名限定租户字段，避免JOIN SQL中的字段歧义：
     * o.`tenant_id` = :__tenant_id_x
     *
     * @param string $alias 表别名，没有别名时为空字符串。
     * @param array $bindParams 用于追加租户ID的绑定参数。
     * @param mixed $tenantId 当前租户ID。
     * @return string SQL条件片段。
     */
    protected function buildTenantCondition(string $alias, array &$bindParams, $tenantId): string
    {
        $field = $this->parseColumn();
        if ($alias !== '') {
            $field = $alias . '.' . $field;
        }

        return $field . ' = ' . $this->bindTenantId($bindParams, $tenantId);
    }

    /**
     * 将租户ID追加到绑定参数中，并返回对应占位符。
     *
     * 生成的绑定名包含当前绑定参数数量和随机后缀，用于避免与业务自定义参数名冲突。
     *
     * @param array $bindParams 需要修改的绑定参数。
     * @param mixed $tenantId 当前租户ID。
     * @return string 带冒号前缀的占位符名称。
     */
    protected function bindTenantId(array &$bindParams, $tenantId): string
    {
        $name = '__tenant_id_' . count($bindParams) . '_' . mt_rand() . '_';
        $bindParams[$name] = [
            $tenantId,
            is_int($tenantId) ? PDO::PARAM_INT : PDO::PARAM_STR,
        ];

        return ':' . $name;
    }

    /**
     * 为配置的租户字段添加SQL标识符引号。
     *
     * 处理器可以返回tenant_id，也可以返回`tenant_id`这样的已引用形式；
     * 该方法会统一转为反引号引用的字段名。
     *
     * @return string 已加反引号的租户字段。
     */
    protected function parseColumn(): string
    {
        $column = trim($this->handler->getTenantIdColumn(), '`"[] ');

        return '`' . str_replace('`', '``', $column) . '`';
    }

    /**
     * 判断数据表是否需要跳过租户改写。
     *
     * 规则：
     * - 表名为空：忽略。
     * - 能读取字段且包含租户字段：不忽略。
     * - 能读取字段但不包含租户字段：忽略。
     * - 无法读取字段：调用handler的兜底判断。
     *
     * @param PDOConnection $connection 当前数据库连接。
     * @param string $table 原始表名或带引号的表名。
     * @return bool true表示跳过租户改写。
     */
    protected function shouldIgnore(PDOConnection $connection, string $table): bool
    {
        $table = $this->cleanIdentifier($table);
        $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        if ($table === '') {
            return true;
        }

        $fields = $this->getTableFields($connection, $table);
        if (!empty($fields)) {
            return !$this->hasTenantColumn($fields);
        }

        return $this->handler->ignoreTable($table);
    }

    /**
     * 从当前连接读取表字段元信息。
     *
     * 字段元信息会优先缓存在当前协程上下文中。同一协程内，同一连接对象和同一表名
     * 只会读取一次表结构，避免每条SQL拦截都触发SHOW COLUMNS等元信息查询。
     *
     * 原生表达式、临时表、数据库权限或不支持的驱动都可能导致元信息读取失败。
     * 失败时同样缓存空数组，让shouldIgnore()继续使用handler兜底，同时避免重复失败查询。
     *
     * @param PDOConnection $connection 当前数据库连接。
     * @param string $table 标准化后的表名。
     * @return array 可读取时返回以字段名为key的字段元信息。
     */
    protected function getTableFields(PDOConnection $connection, string $table): array
    {
        $cacheKey = $this->buildTableFieldsCacheKey($connection, $table);

        if ($this->hasCachedTableFields($cacheKey)) {
            return $this->getCachedTableFields($cacheKey);
        }

        try {
            $fields = $connection->getFields($table);
        } catch (\Throwable $exception) {
            $fields = [];
        }

        $this->setCachedTableFields($cacheKey, $fields);

        return $fields;
    }

    /**
     * 构建表字段缓存key。
     *
     * key包含连接对象ID和表名，避免同一协程中不同数据库连接的同名表互相污染。
     *
     * @param PDOConnection $connection 当前数据库连接。
     * @param string $table 标准化后的表名。
     * @return string
     */
    protected function buildTableFieldsCacheKey(PDOConnection $connection, string $table): string
    {
        return spl_object_id($connection) . ':' . $table;
    }

    /**
     * 判断是否已有表字段缓存。
     *
     * 使用array_key_exists而不是isset，是为了让空数组也能作为有效缓存值。
     *
     * @param string $cacheKey 表字段缓存key。
     * @return bool
     */
    protected function hasCachedTableFields(string $cacheKey): bool
    {
        $cache = $this->getTableFieldsCache();

        return array_key_exists($cacheKey, $cache);
    }

    /**
     * 获取已缓存的表字段元信息。
     *
     * @param string $cacheKey 表字段缓存key。
     * @return array
     */
    protected function getCachedTableFields(string $cacheKey): array
    {
        $cache = $this->getTableFieldsCache();

        return $cache[$cacheKey] ?? [];
    }

    /**
     * 写入表字段元信息缓存。
     *
     * @param string $cacheKey 表字段缓存key。
     * @param array $fields 表字段元信息。
     * @return void
     */
    protected function setCachedTableFields(string $cacheKey, array $fields): void
    {
        $cache = $this->getTableFieldsCache();
        $cache[$cacheKey] = $fields;
        $this->setTableFieldsCache($cache);
    }

    /**
     * 获取当前环境中的表字段缓存。
     *
     * 协程环境使用SwooleContext，这样缓存生命周期跟随当前协程；非协程环境使用对象属性。
     *
     * @return array
     */
    protected function getTableFieldsCache(): array
    {
        if ($this->isCoroutineContextAvailable()) {
            if (!SwooleContext::has(static::CONTEXT_TABLE_FIELDS_CACHE_KEY)) {
                SwooleContext::set(static::CONTEXT_TABLE_FIELDS_CACHE_KEY, []);
            }

            $cache = SwooleContext::get(static::CONTEXT_TABLE_FIELDS_CACHE_KEY);
            if (!is_array($cache)) {
                $cache = [];
                SwooleContext::set(static::CONTEXT_TABLE_FIELDS_CACHE_KEY, $cache);
            }

            return $cache;
        }

        return $this->tableFieldsCache;
    }

    /**
     * 保存当前环境中的表字段缓存。
     *
     * @param array $cache 表字段缓存。
     * @return void
     */
    protected function setTableFieldsCache(array $cache): void
    {
        if ($this->isCoroutineContextAvailable()) {
            SwooleContext::set(static::CONTEXT_TABLE_FIELDS_CACHE_KEY, $cache);
            return;
        }

        $this->tableFieldsCache = $cache;
    }

    /**
     * 判断当前是否可使用协程上下文。
     *
     * 为了兼容CLI测试或未加载Swoole的运行环境，这里先判断类是否存在。
     *
     * @return bool
     */
    protected function isCoroutineContextAvailable(): bool
    {
        return class_exists(SwooleContext::class)
            && \Swoole\Coroutine::getCid() >= 0;
    }

    /**
     * 检查表字段元信息中是否包含配置的租户字段。
     *
     * 当前项目驱动通常会返回以字段名为key的元信息；该方法也会检查内部['name']值，
     * 以兼容其他字段元信息结构。
     *
     * @param array $fields PDOConnection::getFields()返回的字段元信息。
     * @return bool 表中存在租户字段时返回true。
     */
    protected function hasTenantColumn(array $fields): bool
    {
        $tenantColumn = $this->cleanIdentifier($this->handler->getTenantIdColumn());

        if (array_key_exists($tenantColumn, $fields)) {
            return true;
        }

        foreach ($fields as $field => $info) {
            if ($this->cleanIdentifier((string)$field) === $tenantColumn) {
                return true;
            }

            if (is_array($info) && isset($info['name']) && $this->cleanIdentifier((string)$info['name']) === $tenantColumn) {
                return true;
            }
        }

        return false;
    }

    /**
     * 移除常见SQL标识符引号字符。
     *
     * 表名、别名和字段名与元信息比较前，都会先经过该方法清理。
     *
     * @param string $identifier 原始SQL标识符。
     * @return string 去除引号后的标识符。
     */
    protected function cleanIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        $identifier = trim($identifier, '`"[]');

        return str_replace(['`', '"', '[', ']'], '', $identifier);
    }

    /**
     * 标准化解析出来的别名。
     *
     * 空别名保持为空；带引号的别名会先去除引号，再用于限定租户字段。
     *
     * @param string $alias 原始解析别名。
     * @return string 标准化后的别名。
     */
    protected function normalizeAlias(string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '') {
            return '';
        }

        return trim($alias, '`"[]');
    }

    /**
     * 检查解析出来的别名token是否其实是SQL关键字。
     *
     * 基于正则的表名解析在没有别名时，可能把后续SQL关键字误捕获为别名。
     * 该方法用于避免把"FROM users WHERE"中的WHERE当作表别名。
     *
     * @param string $value 候选别名token。
     * @return bool 是SQL关键字时返回true。
     */
    protected function isKeyword(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return in_array(strtoupper($value), [
            'WHERE',
            'JOIN',
            'INNER',
            'LEFT',
            'RIGHT',
            'FULL',
            'CROSS',
            'ON',
            'SET',
            'VALUES',
            'GROUP',
            'HAVING',
            'ORDER',
            'LIMIT',
            'UNION',
            'FOR',
            'LOCK',
        ], true);
    }
}
