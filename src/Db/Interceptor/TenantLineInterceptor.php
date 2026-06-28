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

class TenantLineInterceptor implements SqlInterceptorInterface
{
    /**
     * @var TenantLineHandlerInterface
     */
    protected $handler;

    /**
     * @var bool
     */
    protected $insertFill;

    /**
     * @param TenantLineHandlerInterface $handler
     * @param bool $insertFill
     */
    public function __construct(TenantLineHandlerInterface $handler, bool $insertFill = true)
    {
        $this->handler = $handler;
        $this->insertFill = $insertFill;
    }

    /**
     * @param PDOConnection $connection
     * @param string $sql
     * @param array $bindParams
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
                $this->rewriteSelect($sql, $bindParams, $tenantId);
                break;
            case 'UPDATE':
                $this->rewriteUpdate($sql, $bindParams, $tenantId);
                break;
            case 'DELETE':
                $this->rewriteDelete($sql, $bindParams, $tenantId);
                break;
            case 'INSERT':
            case 'REPLACE':
                if ($this->insertFill) {
                    $this->rewriteInsert($sql, $bindParams, $tenantId);
                }
                break;
        }
    }

    /**
     * @param string $sql
     * @return string
     */
    protected function getStatementType(string $sql): string
    {
        if (preg_match('/^\s*(SELECT|UPDATE|DELETE|INSERT|REPLACE)\b/i', $sql, $matches)) {
            return strtoupper($matches[1]);
        }

        return '';
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @param mixed $tenantId
     * @return void
     */
    protected function rewriteSelect(string &$sql, array &$bindParams, $tenantId): void
    {
        $tableInfo = $this->matchTableAfterKeyword($sql, 'FROM');
        if (!$tableInfo || $this->shouldIgnore($tableInfo['table'])) {
            return;
        }

        $condition = $this->buildTenantCondition($tableInfo['alias'], $bindParams, $tenantId);
        $this->appendWhereCondition($sql, $condition, ['GROUP BY', 'HAVING', 'UNION', 'ORDER BY', 'LIMIT', 'OFFSET', 'FOR UPDATE', 'LOCK IN SHARE MODE']);
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @param mixed $tenantId
     * @return void
     */
    protected function rewriteUpdate(string &$sql, array &$bindParams, $tenantId): void
    {
        if (!preg_match('/^\s*UPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)?(`[^`]+`|"[^"]+"|\[[^\]]+\]|[\w.]+)(?:\s+(?:AS\s+)?(`?[a-zA-Z_][\w]*`?|"[a-zA-Z_][\w]*"|\[[a-zA-Z_][\w]*\]))?\s+(?:SET|JOIN|INNER|LEFT|RIGHT|FULL|CROSS)\b/i', $sql, $matches)) {
            return;
        }

        $table = $this->cleanIdentifier($matches[1]);
        if ($this->shouldIgnore($table)) {
            return;
        }

        $alias = $this->normalizeAlias($matches[2] ?? '');
        $condition = $this->buildTenantCondition($alias, $bindParams, $tenantId);
        $this->appendWhereCondition($sql, $condition, ['ORDER BY', 'LIMIT']);
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @param mixed $tenantId
     * @return void
     */
    protected function rewriteDelete(string &$sql, array &$bindParams, $tenantId): void
    {
        $tableInfo = $this->matchTableAfterKeyword($sql, 'FROM');
        if (!$tableInfo || $this->shouldIgnore($tableInfo['table'])) {
            return;
        }

        $condition = $this->buildTenantCondition($tableInfo['alias'], $bindParams, $tenantId);
        $this->appendWhereCondition($sql, $condition, ['ORDER BY', 'LIMIT']);
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @param mixed $tenantId
     * @return void
     */
    protected function rewriteInsert(string &$sql, array &$bindParams, $tenantId): void
    {
        $tableInfo = $this->matchTableAfterKeyword($sql, 'INTO');
        if (!$tableInfo || $this->shouldIgnore($tableInfo['table'])) {
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
     * @param string $sql
     * @param string $keyword
     * @return array
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
     * @param string $sql
     * @param string $condition
     * @param array $tailKeywords
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
     * @param string $sql
     * @param array $keywords
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
     * @param string $sql
     * @param array $bindParams
     * @param mixed $tenantId
     * @return bool
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
     * @param string $sql
     * @param array $bindParams
     * @param mixed $tenantId
     * @return bool
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
     * @param string $values
     * @return array
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
     * @param string $values
     * @return array
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
     * @param string $sql
     * @param string $column
     * @return bool
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
     * @param string $alias
     * @param array $bindParams
     * @param mixed $tenantId
     * @return string
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
     * @param array $bindParams
     * @param mixed $tenantId
     * @return string
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
     * @return string
     */
    protected function parseColumn(): string
    {
        $column = trim($this->handler->getTenantIdColumn(), '`"[] ');

        return '`' . str_replace('`', '``', $column) . '`';
    }

    /**
     * @param string $table
     * @return bool
     */
    protected function shouldIgnore(string $table): bool
    {
        $table = $this->cleanIdentifier($table);
        $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        return $table === '' || $this->handler->ignoreTable($table);
    }

    /**
     * @param string $identifier
     * @return string
     */
    protected function cleanIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        $identifier = trim($identifier, '`"[]');

        return str_replace(['`', '"', '[', ']'], '', $identifier);
    }

    /**
     * @param string $alias
     * @return string
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
     * @param string $value
     * @return bool
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
