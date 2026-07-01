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
use Swoolefy\Library\Db\Concern\TenantScopeContext;
use Swoolefy\Library\Db\Concern\TenantTableMetadata;
use Swoolefy\Library\Db\PDOConnection;

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
     * @param TenantLineHandlerInterface $handler 租户元信息处理器。
     * @param bool $insertFill INSERT / REPLACE时是否自动填充租户字段。
     */
    public function __construct(TenantLineHandlerInterface $handler, bool $insertFill = true)
    {
        $this->handler = $handler;
        $this->insertFill = $insertFill;
        TenantScopeContext::bindHandler($handler);
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
        $tableInfo = $this->matchOutermostTableAfterKeyword($sql, 'FROM');
        if (!$tableInfo || $this->shouldIgnore($connection, $tableInfo['table'])) {
            return;
        }

        if ($this->hasTenantCondition($sql, $tableInfo['alias'], $tableInfo['table'])) {
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
        if ($this->hasTenantCondition($sql, $alias, $table)) {
            return;
        }

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
        $tableInfo = $this->matchOutermostTableAfterKeyword($sql, 'FROM');
        if (!$tableInfo || $this->shouldIgnore($connection, $tableInfo['table'])) {
            return;
        }

        if ($this->hasTenantCondition($sql, $tableInfo['alias'], $tableInfo['table'])) {
            return;
        }

        $condition = $this->buildTenantCondition($tableInfo['alias'], $bindParams, $tenantId);
        $this->appendWhereCondition($sql, $condition, ['ORDER BY', 'LIMIT']);
    }

    /**
     * 改写INSERT / REPLACE语句，自动填充租户字段。
     *
     * 如果字段列表或SET列表中已经包含租户字段，则保持SQL不变。否则VALUES语法会给
     * 每一行追加租户占位符，SET语法会在SET列表末尾追加租户字段赋值，SELECT语法会在
     * 字段列表与SELECT投影末尾追加租户字段和占位符。
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

        if ($this->rewriteInsertValues($sql, $bindParams, $tenantId)) {
            return;
        }

        $this->rewriteInsertSelect($sql, $bindParams, $tenantId);
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
     * 仅匹配最外层 SELECT 的 FROM 真实表名；FROM 子查询 `( SELECT ... ) alias` 时返回空。
     *
     * @param string $sql
     * @param string $keyword
     * @return array{table:string,alias:string}|array
     */
    protected function matchOutermostTableAfterKeyword(string $sql, string $keyword): array
    {
        $keywordLength = strlen($keyword);
        $sqlLength = strlen($sql);
        $depth = 0;
        $quote = '';
        $escaped = false;

        for ($i = 0; $i < $sqlLength; $i++) {
            $char = $sql[$i];

            if ($quote !== '') {
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

            if ($char === '`') {
                for ($j = $i + 1; $j < $sqlLength; $j++) {
                    if ($sql[$j] === '`') {
                        $i = $j;
                        break;
                    }
                }
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                continue;
            }

            if ($depth !== 0 || $i + $keywordLength > $sqlLength) {
                continue;
            }

            if (strcasecmp(substr($sql, $i, $keywordLength), $keyword) !== 0) {
                continue;
            }

            $before = $i > 0 ? $sql[$i - 1] : ' ';
            $after = $i + $keywordLength < $sqlLength ? $sql[$i + $keywordLength] : ' ';
            if (preg_match('/[\w]/', $before) || !preg_match('/\s/u', $after)) {
                continue;
            }

            $remainder = ltrim(substr($sql, $i + $keywordLength));
            if ($remainder === '' || $remainder[0] === '(') {
                return [];
            }

            $pattern = '/^(`[^`]+`|"[^"]+"|\[[^\]]+\]|[\w.]+)(?:\s+(?:AS\s+)?(`?[a-zA-Z_][\w]*`?|"[a-zA-Z_][\w]*"|\[[a-zA-Z_][\w]*\]))?/i';
            if (!preg_match($pattern, $remainder, $matches)) {
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

        return [];
    }

    /**
     * 判断 SQL 中是否已存在租户字段过滤，避免 buildSql + 拦截器或 Model Scope 重复追加。
     *
     * 支持占位符（:name、?）与字面量（数字、单/双引号字符串）。
     */
    protected function hasTenantCondition(string $sql, string $alias, string $table = ''): bool
    {
        $column = preg_quote(trim($this->handler->getTenantIdColumn(), '`"[] '), '/');
        $value = $this->tenantConditionRhsPattern();
        $patterns = [];

        if ($alias !== '') {
            $aliasPattern = preg_quote(trim($alias, '`"[] '), '/');
            $patterns[] = '/(?:`?' . $aliasPattern . '`?\.)`?' . $column . '`?\s*=\s*' . $value . '/i';
        }

        if ($table !== '') {
            $tablePattern = preg_quote(trim($table, '`"[] '), '/');
            $patterns[] = '/(?:`?' . $tablePattern . '`?\.)`?' . $column . '`?\s*=\s*' . $value . '/i';
        }

        $patterns[] = '/(?:^|[\s\(,])`?' . $column . '`?\s*=\s*' . $value . '/i';

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 租户条件右侧取值：PDO 占位符或 SQL 字面量。
     */
    protected function tenantConditionRhsPattern(): string
    {
        return '(?:' . implode('|', [
            ':\w+',
            '\?',
            '-?\d+(?:\.\d+)?',
            '\'[^\']*\'',
            '"[^"]*"',
        ]) . ')';
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
     * 查找 WHERE 子句应该插入到尾部子句之前的位置。
     *
     * 仅在括号 depth=0 且不在字符串/标识符引号内匹配，避免误命中字面量或子查询中的关键字。
     *
     * @param string $sql 待检查的 SQL。
     * @param array $keywords 候选尾部子句。
     * @return int|false 命中关键字前空白起始偏移，未命中返回 false。
     */
    protected function findTailOffset(string $sql, array $keywords)
    {
        if ($keywords === []) {
            return false;
        }

        return $this->findAnyKeywordOffset($sql, $keywords, 0);
    }

    /**
     * 在 depth=0 且不在引号内查找单个 SQL 关键字（可指定最小偏移）。
     *
     * @return int|false 关键字前空白起始偏移，未命中返回 false。
     */
    protected function findKeywordOffset(string $sql, string $keyword, int $minOffset = 0)
    {
        return $this->findAnyKeywordOffset($sql, [$keyword], $minOffset);
    }

    /**
     * 在 depth=0 且不在引号内查找 SQL 关键字（按长度降序、从左到右首个命中）。
     *
     * @param string $sql
     * @param array $keywords
     * @param int $minOffset 忽略该偏移之前的关键字。
     * @return int|false
     */
    protected function findAnyKeywordOffset(string $sql, array $keywords, int $minOffset = 0)
    {
        if ($keywords === []) {
            return false;
        }

        $keywords = array_values(array_unique($keywords));
        usort($keywords, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $sqlLength = strlen($sql);
        $depth = 0;
        $quote = '';
        $escaped = false;

        for ($i = 0; $i < $sqlLength; $i++) {
            $char = $sql[$i];

            if ($quote !== '') {
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

            if ($char === '`') {
                for ($j = $i + 1; $j < $sqlLength; $j++) {
                    if ($sql[$j] === '`') {
                        $i = $j;
                        break;
                    }
                }
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                if ($depth > 0) {
                    $depth--;
                }
                continue;
            }

            if ($depth !== 0 || !preg_match('/\s/u', $char)) {
                continue;
            }

            $wsStart = $i;
            while ($i + 1 < $sqlLength && preg_match('/\s/u', $sql[$i + 1])) {
                $i++;
            }

            if ($wsStart < $minOffset) {
                continue;
            }

            $remainder = substr($sql, $i + 1);
            foreach ($keywords as $keyword) {
                if ($this->startsWithSqlKeyword($remainder, $keyword)) {
                    return $wsStart;
                }
            }
        }

        return false;
    }

    /**
     * 判断文本是否以指定 SQL 关键字开头（大小写不敏感，并要求关键字后有词边界）。
     */
    protected function startsWithSqlKeyword(string $text, string $keyword): bool
    {
        $keywordLength = strlen($keyword);
        if ($text === '' || strlen($text) < $keywordLength) {
            return false;
        }

        if (strcasecmp(substr($text, 0, $keywordLength), $keyword) !== 0) {
            return false;
        }

        if (strlen($text) === $keywordLength) {
            return true;
        }

        $after = $text[$keywordLength];

        return preg_match('/\s/u', $after) || !preg_match('/[\w]/', $after);
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
     * 为 INSERT ... SELECT 语法填充租户 ID。
     *
     * 示例：
     * INSERT INTO orders (user_id) SELECT user_id FROM src
     * 改写为：
     * INSERT INTO orders (user_id, `tenant_id`) SELECT user_id , :__tenant_id_x FROM src
     *
     * @param string $sql
     * @param array $bindParams
     * @param mixed $tenantId
     * @return bool
     */
    protected function rewriteInsertSelect(string &$sql, array &$bindParams, $tenantId): bool
    {
        $selectOffset = $this->findKeywordOffset($sql, 'SELECT');
        if ($selectOffset === false) {
            return false;
        }

        $valuesOffset = $this->findKeywordOffset($sql, 'VALUES');
        if ($valuesOffset !== false && $valuesOffset < $selectOffset) {
            return false;
        }

        $head = substr($sql, 0, $selectOffset);
        $selectAndTail = ltrim(substr($sql, $selectOffset));
        if (!preg_match('/^SELECT\s+/is', $selectAndTail)) {
            return false;
        }

        $dupOffset = $this->findTailOffset($selectAndTail, ['ON DUPLICATE KEY UPDATE']);
        $selectSql = false === $dupOffset ? $selectAndTail : substr($selectAndTail, 0, $dupOffset);
        $dupTail = false === $dupOffset ? '' : substr($selectAndTail, $dupOffset);

        $minFromOffset = 0;
        if (preg_match('/^SELECT\s+/is', $selectSql, $selectMatch, PREG_OFFSET_CAPTURE)) {
            $minFromOffset = $selectMatch[0][1] + strlen($selectMatch[0][0]);
        }

        $fromOffset = $this->findKeywordOffset($selectSql, 'FROM', $minFromOffset);
        if ($fromOffset === false) {
            return false;
        }

        $selectListPart = rtrim(substr($selectSql, 0, $fromOffset));
        $fromAndRest = substr($selectSql, $fromOffset);
        $placeholder = $this->bindTenantId($bindParams, $tenantId);
        $newSelectSql = $selectListPart . ' , ' . $placeholder . ' ' . ltrim($fromAndRest);
        $newHead = $this->appendInsertHeadColumn($head);

        $sql = rtrim($newHead) . ' ' . $newSelectSql . $dupTail;

        return true;
    }

    /**
     * 在 INSERT 头部字段列表末尾追加租户字段；无显式字段列表时保持 head 不变。
     */
    protected function appendInsertHeadColumn(string $head): string
    {
        $trimmedHead = rtrim($head);
        if (!preg_match('/\(([^()]*)\)\s*$/s', $trimmedHead, $matches)) {
            return $head;
        }

        $fields = rtrim($matches[1]);
        $headPrefix = substr($trimmedHead, 0, -strlen($matches[0]));

        return rtrim($headPrefix) . ' (' . $fields . ', ' . $this->parseColumn() . ')';
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
            return (bool) preg_match('/(^|,)\s*`?' . $column . '`?\s*(,|$)/i', $matches[1]);
        }

        if (preg_match('/\((.*?)\)\s*SELECT\s/is', $sql, $matches)) {
            return (bool) preg_match('/(^|,)\s*`?' . $column . '`?\s*(,|$)/i', $matches[1]);
        }

        if (preg_match('/\bSET\b(.*?)(?:\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+|$)/is', $sql, $matches)) {
            return (bool) preg_match('/(^|,)\s*`?' . $column . '`?\s*=/i', $matches[1]);
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
            PDO::PARAM_STR,
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
        return TenantTableMetadata::shouldIgnore($connection, $table, $this->handler);
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
        return TenantTableMetadata::cleanIdentifier($identifier);
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
