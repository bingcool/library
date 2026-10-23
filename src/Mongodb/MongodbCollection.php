<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mongodb;

use Generator;
use InvalidArgumentException;
use MongoDB\BulkWriteResult;
use MongoDB\Collection;
use MongoDB\DeleteResult;
use MongoDB\InsertManyResult;
use MongoDB\InsertOneResult;
use MongoDB\Operation\FindOneAndUpdate;
use MongoDB\UpdateResult;
use Swoolefy\Library\Mongodb\Config\MongodbConfig;
use Swoolefy\Library\Mongodb\Support\IdNormalizer;

/**
 * MongoDB\Collection 的轻量包装：强制数组解码、统一 _id 语义、写操作返回原生结果对象。
 */
final class MongodbCollection
{
    private readonly IdNormalizer $ids;

    public function __construct(
        private readonly Collection $collection,
        string $idKey = '_id',
        bool $objectId = true
    ) {
        $this->ids = new IdNormalizer($idKey, $objectId);
    }

    public function raw(): Collection
    {
        return $this->collection;
    }

    public function getName(): string
    {
        return $this->collection->getCollectionName();
    }

    public function getNamespace(): string
    {
        return $this->collection->getNamespace();
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function find(array $filter = [], array $options = []): array
    {
        return iterator_to_array($this->iterate($filter, $options), false);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     * @return Generator<int, array<string, mixed>>
     */
    public function iterate(array $filter = [], array $options = []): Generator
    {
        $filter = $this->ids->normalizeFilter($filter);
        $options = $this->readOptions($options);

        foreach ($this->collection->find($filter, $options) as $document) {
            yield $this->ids->normalizeDocument($document);
        }
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function findOne(array $filter = [], array $options = []): ?array
    {
        $filter = $this->ids->normalizeFilter($filter);
        $document = $this->collection->findOne($filter, $this->readOptions($options));

        return $document === null ? null : $this->ids->normalizeDocument($document);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     */
    public function countDocuments(array $filter = [], array $options = []): int
    {
        $this->rejectDecodeOptions($options);

        return $this->collection->countDocuments($this->ids->normalizeFilter($filter), $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function estimatedDocumentCount(array $options = []): int
    {
        $this->rejectDecodeOptions($options);

        return $this->collection->estimatedDocumentCount($options);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     * @return list<mixed>
     */
    public function distinct(string $fieldName, array $filter = [], array $options = []): array
    {
        $this->rejectDecodeOptions($options);

        return $this->collection->distinct($fieldName, $this->ids->normalizeFilter($filter), $options);
    }

    /**
     * 聚合结果不做 _id 归一化，避免破坏 $group 的分组键。
     *
     * @param list<array<string, mixed>> $pipeline
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function aggregate(array $pipeline, array $options = []): array
    {
        $options = $this->readOptions($options);

        return iterator_to_array($this->collection->aggregate($pipeline, $options), false);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function findOneAndDelete(array $filter, array $options = []): ?array
    {
        $options = $this->findOneOptions($options, false);
        $document = $this->collection->findOneAndDelete($this->ids->normalizeFilter($filter), $options);

        return $document === null ? null : $this->ids->normalizeDocument($document);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $replacement
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function findOneAndReplace(array $filter, array $replacement, array $options = []): ?array
    {
        $options = $this->findOneOptions($options, true);
        $document = $this->collection->findOneAndReplace(
            $this->ids->normalizeFilter($filter),
            $replacement,
            $options
        );

        return $document === null ? null : $this->ids->normalizeDocument($document);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function findOneAndUpdate(array $filter, array $update, array $options = []): ?array
    {
        $options = $this->findOneOptions($options, true);
        $document = $this->collection->findOneAndUpdate(
            $this->ids->normalizeFilter($filter),
            $update,
            $options
        );

        return $document === null ? null : $this->ids->normalizeDocument($document);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $options
     */
    public function insertOne(array $document, array $options = []): InsertOneResult
    {
        return $this->collection->insertOne($this->ids->normalizeInsertDocument($document), $options);
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @param array<string, mixed> $options
     */
    public function insertMany(array $documents, array $options = []): InsertManyResult
    {
        $normalized = [];
        foreach ($documents as $index => $document) {
            $normalized[$index] = $this->ids->normalizeInsertDocument($document);
        }

        return $this->collection->insertMany($normalized, $options);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     * @param array<string, mixed> $options
     */
    public function updateOne(array $filter, array $update, array $options = []): UpdateResult
    {
        return $this->collection->updateOne($this->ids->normalizeFilter($filter), $update, $options);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     * @param array<string, mixed> $options
     */
    public function updateMany(array $filter, array $update, array $options = []): UpdateResult
    {
        return $this->collection->updateMany($this->ids->normalizeFilter($filter), $update, $options);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $replacement
     * @param array<string, mixed> $options
     */
    public function replaceOne(array $filter, array $replacement, array $options = []): UpdateResult
    {
        return $this->collection->replaceOne($this->ids->normalizeFilter($filter), $replacement, $options);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     */
    public function deleteOne(array $filter, array $options = []): DeleteResult
    {
        return $this->collection->deleteOne($this->ids->normalizeFilter($filter), $options);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     */
    public function deleteMany(array $filter, array $options = []): DeleteResult
    {
        return $this->collection->deleteMany($this->ids->normalizeFilter($filter), $options);
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, mixed> $options
     */
    public function bulkWrite(array $operations, array $options = []): BulkWriteResult
    {
        return $this->collection->bulkWrite($this->normalizeBulkOperations($operations), $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function readOptions(array $options): array
    {
        $this->rejectDecodeOptions($options);
        $options['typeMap'] = MongodbConfig::TYPE_MAP;

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function rejectDecodeOptions(array $options): void
    {
        foreach (['typeMap', 'codec'] as $key) {
            if (array_key_exists($key, $options)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Mongodb option "%s" is managed by the module; use raw() for custom decoding.',
                        $key
                    )
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function findOneOptions(array $options, bool $allowReturnDocument): array
    {
        if (array_key_exists('includeResultMetadata', $options)) {
            throw new InvalidArgumentException(
                'Mongodb option "includeResultMetadata" is not supported by mongodb/mongodb v2.3.'
            );
        }

        if (array_key_exists('returnDocument', $options)) {
            if (!$allowReturnDocument) {
                throw new InvalidArgumentException(
                    'Mongodb option "returnDocument" is not supported by findOneAndDelete().'
                );
            }

            $returnDocument = $options['returnDocument'];
            if (
                $returnDocument !== FindOneAndUpdate::RETURN_DOCUMENT_BEFORE
                && $returnDocument !== FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ) {
                throw new InvalidArgumentException(
                    'Mongodb option "returnDocument" must be FindOneAndUpdate::RETURN_DOCUMENT_BEFORE (1) '
                    . 'or FindOneAndUpdate::RETURN_DOCUMENT_AFTER (2).'
                );
            }
        }

        return $this->readOptions($options);
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @return list<array<string, mixed>>
     */
    private function normalizeBulkOperations(array $operations): array
    {
        foreach ($operations as $index => $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $type = array_key_first($operation);
            if (!is_string($type)) {
                continue;
            }

            $args = $operation[$type] ?? null;
            if (!is_array($args)) {
                continue;
            }

            switch ($type) {
                case 'insertOne':
                    if (isset($args[0]) && is_array($args[0])) {
                        $args[0] = $this->ids->normalizeInsertDocument($args[0]);
                    }
                    break;

                case 'updateOne':
                case 'updateMany':
                case 'replaceOne':
                case 'deleteOne':
                case 'deleteMany':
                    if (isset($args[0]) && is_array($args[0])) {
                        $args[0] = $this->ids->normalizeFilter($args[0]);
                    }
                    break;
            }

            $operation[$type] = $args;
            $operations[$index] = $operation;
        }

        return $operations;
    }
}
