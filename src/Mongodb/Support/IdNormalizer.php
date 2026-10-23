<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mongodb\Support;

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;

/**
 * _id 读写归一化。
 *
 * 只处理文档顶层 _id；嵌套字段中的 ObjectId 原样保留。
 */
final class IdNormalizer
{
    private const ID_OPERATORS = ['$eq', '$ne', '$in', '$nin', '$all'];

    private const LOGICAL_OPERATORS = ['$and', '$or', '$nor'];

    public function __construct(
        private readonly string $idKey = '_id',
        private readonly bool $objectId = true
    ) {
    }

    /**
     * 归一化过滤条件：id_key 别名、顶层逻辑操作符递归、_id 的 24 位十六进制字符串转 ObjectId。
     *
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public function normalizeFilter(array $filter): array
    {
        if ($this->idKey !== '_id') {
            if (array_key_exists('_id', $filter)) {
                unset($filter[$this->idKey]);
            } elseif (array_key_exists($this->idKey, $filter)) {
                $filter['_id'] = $filter[$this->idKey];
                unset($filter[$this->idKey]);
            }
        }

        foreach (self::LOGICAL_OPERATORS as $operator) {
            if (!isset($filter[$operator]) || !is_array($filter[$operator])) {
                continue;
            }

            foreach ($filter[$operator] as $index => $branch) {
                if (is_array($branch)) {
                    $filter[$operator][$index] = $this->normalizeFilter($branch);
                }
            }
        }

        if ($this->objectId && array_key_exists('_id', $filter)) {
            $filter['_id'] = $this->normalizeIdValue($filter['_id']);
        }

        return $filter;
    }

    /**
     * 归一化待插入文档：不修改调用方数组；id_key 与 _id 同时出现时抛异常。
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function normalizeInsertDocument(array $document): array
    {
        if ($this->idKey !== '_id') {
            $hasId = array_key_exists('_id', $document);
            $hasAlias = array_key_exists($this->idKey, $document);

            if ($hasId && $hasAlias) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Document contains both "_id" and "%s"; remove one of them before inserting.',
                        $this->idKey
                    )
                );
            }

            if ($hasAlias) {
                $document['_id'] = $document[$this->idKey];
                unset($document[$this->idKey]);
            }
        }

        if ($this->objectId && array_key_exists('_id', $document)) {
            $document['_id'] = $this->normalizeIdValue($document['_id']);
        }

        return $document;
    }

    /**
     * 归一化读出的文档：ObjectId 主键转字符串，其他主键类型原样保留。
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function normalizeDocument(array $document): array
    {
        if (!array_key_exists('_id', $document)) {
            return $document;
        }

        $id = $document['_id'];
        if ($this->objectId && $id instanceof ObjectId) {
            $id = (string) $id;
        }

        if ($this->idKey === '_id') {
            $document['_id'] = $id;
            return $document;
        }

        if (
            array_key_exists($this->idKey, $document)
            && $document[$this->idKey] !== null
            && $document[$this->idKey] !== ''
        ) {
            $document['_id'] = $id;
            return $document;
        }

        $document[$this->idKey] = $id;
        unset($document['_id']);

        return $document;
    }

    private function normalizeIdValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->toObjectId($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::ID_OPERATORS, true)) {
                $value[$key] = $this->normalizeOperatorValue($item);
            }
        }

        return $value;
    }

    private function normalizeOperatorValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->toObjectId($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $index => $item) {
            if (is_string($item)) {
                $value[$index] = $this->toObjectId($item);
            }
        }

        return $value;
    }

    private function toObjectId(string $value): string|ObjectId
    {
        if (strlen($value) === 24 && ctype_xdigit($value)) {
            return new ObjectId($value);
        }

        return $value;
    }
}
