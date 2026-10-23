<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\MongoDb;

use InvalidArgumentException;
use MongoDB\BulkWriteResult;
use MongoDB\BSON\ObjectId;
use MongoDB\DeleteResult;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\InsertManyResult;
use MongoDB\InsertOneResult;
use MongoDB\Operation\FindOneAndUpdate;
use MongoDB\UpdateResult;
use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Mongodb\MongodbCollection;
use Swoolefy\Library\Mongodb\MongodbModel;
use Throwable;

/**
 * 真实 MongoDB 集成测试。
 *
 * 需要 ext-mongodb >= 2.3，并通过 MONGO_URI / MONGO_DB 指定测试实例；
 * 未配置或不可达时整体跳过。只操作 phpunit_mongodb_ 前缀的 collection。
 */
final class MongodbIntegrationTest extends TestCase
{
    private const HEX_ID = '507f1f77bcf86cd799439011';

    private const MISSING_ID = 'ffffffffffffffffffffffff';

    private static ?MongodbModel $model = null;

    private static string $uri = '';

    private static string $database = '';

    /** @var list<string> */
    private array $collections = [];

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('mongodb')) {
            self::markTestSkipped('ext-mongodb is not loaded.');
        }

        if (version_compare((string) phpversion('mongodb'), '2.3.0', '<')) {
            self::markTestSkipped('ext-mongodb >= 2.3 is required.');
        }

        $uri = getenv('MONGO_URI');
        if (!is_string($uri) || $uri === '') {
            self::markTestSkipped('MONGO_URI is not set.');
        }

        $database = getenv('MONGO_DB');
        self::$uri = $uri;
        self::$database = is_string($database) && $database !== '' ? $database : 'phpunit_library';

        try {
            $model = new MongodbModel([
                'uri' => self::$uri,
                'database' => self::$database,
                'uri_options' => ['serverSelectionTimeoutMS' => 2000],
            ]);

            if (!$model->ping()) {
                self::markTestSkipped('MongoDB ping failed.');
            }

            self::$model = $model;
        } catch (Throwable $exception) {
            self::markTestSkipped('MongoDB is not reachable: ' . $exception->getMessage());
        }
    }

    protected function setUp(): void
    {
        if (!self::$model instanceof MongodbModel) {
            self::markTestSkipped('MongoDB is not available.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->collections as $collection) {
            try {
                self::$model?->collection($collection)->raw()->drop();
            } catch (Throwable) {
                // 清理失败不覆盖测试结果
            }
        }

        $this->collections = [];
    }

    public function testInsertFindOneAndDelete(): void
    {
        $collection = $this->newCollection('crud_');

        $inserted = $collection->insertOne([
            '_id' => self::HEX_ID,
            'name' => 'demo',
            'nested' => ['enabled' => true],
        ]);

        $this->assertInstanceOf(InsertOneResult::class, $inserted);
        $this->assertInstanceOf(ObjectId::class, $inserted->getInsertedId());
        $this->assertSame(self::HEX_ID, (string) $inserted->getInsertedId());

        $document = $collection->findOne(['_id' => self::HEX_ID]);
        $this->assertIsArray($document);
        $this->assertSame(self::HEX_ID, $document['_id']);
        $this->assertSame(['enabled' => true], $document['nested']);
        $this->assertNull($collection->findOne(['_id' => self::MISSING_ID]));

        $deleted = $collection->deleteOne(['_id' => self::HEX_ID]);
        $this->assertInstanceOf(DeleteResult::class, $deleted);
        $this->assertSame(1, $deleted->getDeletedCount());
        $this->assertNull($collection->findOne(['_id' => self::HEX_ID]));
    }

    public function testInsertManyWithSingleDocumentIsNotNested(): void
    {
        $collection = $this->newCollection('insert_many_');

        $inserted = $collection->insertMany([
            ['name' => 'only'],
        ]);

        $this->assertInstanceOf(InsertManyResult::class, $inserted);
        $this->assertSame(1, $inserted->getInsertedCount());
        $this->assertCount(1, $inserted->getInsertedIds());

        $documents = $collection->find([]);
        $this->assertCount(1, $documents);
        $this->assertSame('only', $documents[0]['name']);
        $this->assertArrayNotHasKey('0', $documents[0]);
    }

    public function testUpdateResultKeepsMatchedAndModifiedSemantics(): void
    {
        $collection = $this->newCollection('update_');
        $collection->insertOne(['_id' => self::HEX_ID, 'status' => 'new']);

        $unchanged = $collection->updateOne(['_id' => self::HEX_ID], ['$set' => ['status' => 'new']]);
        $this->assertInstanceOf(UpdateResult::class, $unchanged);
        $this->assertSame(1, $unchanged->getMatchedCount());
        $this->assertSame(0, $unchanged->getModifiedCount());
        $this->assertSame(0, $unchanged->getUpsertedCount());

        $changed = $collection->updateOne(['_id' => self::HEX_ID], ['$set' => ['status' => 'done']]);
        $this->assertSame(1, $changed->getMatchedCount());
        $this->assertSame(1, $changed->getModifiedCount());

        $upserted = $collection->updateOne(
            ['_id' => '0123456789abcdef01234567'],
            ['$set' => ['status' => 'created']],
            ['upsert' => true]
        );
        $this->assertSame(0, $upserted->getMatchedCount());
        $this->assertSame(1, $upserted->getUpsertedCount());
        $this->assertNotNull($upserted->getUpsertedId());
    }

    public function testDeleteZeroIsNotFailure(): void
    {
        $collection = $this->newCollection('delete_');

        $deleted = $collection->deleteOne(['_id' => self::MISSING_ID]);

        $this->assertInstanceOf(DeleteResult::class, $deleted);
        $this->assertSame(0, $deleted->getDeletedCount());

        $deletedMany = $collection->deleteMany([]);
        $this->assertSame(0, $deletedMany->getDeletedCount());
    }

    public function testCountDocumentsAndEstimatedCount(): void
    {
        $collection = $this->newCollection('count_');
        $collection->insertMany([
            ['tag' => 'a'],
            ['tag' => 'a'],
            ['tag' => 'b'],
        ]);

        $this->assertSame(3, $collection->countDocuments([]));
        $this->assertSame(2, $collection->countDocuments(['tag' => 'a']));
        $this->assertGreaterThanOrEqual(3, $collection->estimatedDocumentCount());
    }

    public function testNonObjectIdKeysArePreserved(): void
    {
        $collection = $this->newCollection('keys_');
        $collection->insertMany([
            ['_id' => 'order-1', 'kind' => 'string'],
            ['_id' => 42, 'kind' => 'int'],
            ['_id' => ['tenant' => 't1', 'seq' => 1], 'kind' => 'compound'],
            ['_id' => 'abcdefabcdefabcdefabcdef', 'kind' => 'hex'],
        ]);

        $this->assertSame('order-1', $collection->findOne(['_id' => 'order-1'])['_id']);
        $this->assertSame(42, $collection->findOne(['_id' => 42])['_id']);
        $this->assertSame(
            ['tenant' => 't1', 'seq' => 1],
            $collection->findOne(['_id' => ['tenant' => 't1', 'seq' => 1]])['_id']
        );
        $this->assertSame(
            'abcdefabcdefabcdefabcdef',
            $collection->findOne(['_id' => 'abcdefabcdefabcdefabcdef'])['_id']
        );

        $this->assertSame(1, $collection->updateOne(['_id' => 'order-1'], ['$set' => ['kind' => 'updated']])->getModifiedCount());
        $this->assertSame(1, $collection->deleteOne(['_id' => 42])->getDeletedCount());
    }

    public function testFindOneAndUpdateBeforeAndAfter(): void
    {
        $collection = $this->newCollection('find_update_');
        $collection->insertOne(['_id' => self::HEX_ID, 'count' => 1]);

        $before = $collection->findOneAndUpdate(
            ['_id' => self::HEX_ID],
            ['$inc' => ['count' => 1]]
        );
        $this->assertIsArray($before);
        $this->assertSame(1, $before['count']);
        $this->assertSame(self::HEX_ID, $before['_id']);

        $after = $collection->findOneAndUpdate(
            ['_id' => self::HEX_ID],
            ['$inc' => ['count' => 1]],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );
        $this->assertIsArray($after);
        $this->assertSame(3, $after['count']);

        $this->assertNull(
            $collection->findOneAndUpdate(['_id' => self::MISSING_ID], ['$inc' => ['count' => 1]])
        );
    }

    public function testFindOneAndReplaceAndDelete(): void
    {
        $collection = $this->newCollection('find_replace_');
        $collection->insertOne(['_id' => self::HEX_ID, 'name' => 'old']);

        $replaced = $collection->findOneAndReplace(
            ['_id' => self::HEX_ID],
            ['name' => 'new'],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );
        $this->assertSame('new', $replaced['name']);
        $this->assertSame(self::HEX_ID, $replaced['_id']);

        $deleted = $collection->findOneAndDelete(['_id' => self::HEX_ID]);
        $this->assertSame('new', $deleted['name']);
        $this->assertNull($collection->findOne(['_id' => self::HEX_ID]));
    }

    public function testAggregateReturnsArraysWithoutRewritingGroupId(): void
    {
        $collection = $this->newCollection('aggregate_');
        $collection->insertMany([
            ['group' => 'a'],
            ['group' => 'a'],
            ['group' => 'b'],
        ]);

        $rows = $collection->aggregate([
            ['$group' => ['_id' => '$group', 'total' => ['$sum' => 1]]],
            ['$sort' => ['_id' => 1]],
        ]);

        $this->assertSame(
            [
                ['_id' => 'a', 'total' => 2],
                ['_id' => 'b', 'total' => 1],
            ],
            $rows
        );
    }

    public function testDistinctKeepsRawValues(): void
    {
        $collection = $this->newCollection('distinct_');
        $collection->insertMany([
            ['_id' => '111111111111111111111111', 'tag' => 'a'],
            ['_id' => '222222222222222222222222', 'tag' => 'b'],
        ]);

        $ids = $collection->distinct('_id');
        $this->assertCount(2, $ids);
        $this->assertContainsOnlyInstancesOf(ObjectId::class, $ids);

        $tags = $collection->distinct('tag', ['_id' => '111111111111111111111111']);
        $this->assertSame(['a'], $tags);
    }

    public function testBulkWriteNormalizesPositionalOperations(): void
    {
        $collection = $this->newCollection('bulk_');

        $result = $collection->bulkWrite([
            ['insertOne' => [['_id' => '111111111111111111111111', 'n' => 1]]],
            ['insertOne' => [['_id' => '222222222222222222222222', 'n' => 5]]],
            ['updateOne' => [['_id' => '111111111111111111111111'], ['$set' => ['n' => 2]]]],
            ['deleteMany' => [['n' => 5]]],
        ]);

        $this->assertInstanceOf(BulkWriteResult::class, $result);
        $this->assertSame(2, $result->getInsertedCount());
        $this->assertSame(1, $result->getModifiedCount());
        $this->assertSame(1, $result->getDeletedCount());

        $document = $collection->findOne(['_id' => '111111111111111111111111']);
        $this->assertSame(2, $document['n']);
        $this->assertSame('111111111111111111111111', $document['_id']);
    }

    public function testIdKeyAliasEndToEnd(): void
    {
        $model = new MongodbModel([
            'uri' => self::$uri,
            'database' => self::$database,
            'id_key' => 'id',
        ]);

        $name = $this->registerCollection('alias_');
        $collection = $model->collection($name);

        $collection->insertOne(['id' => self::HEX_ID, 'name' => 'alias']);
        $document = $collection->findOne(['id' => self::HEX_ID]);

        $this->assertSame(self::HEX_ID, $document['id']);
        $this->assertArrayNotHasKey('_id', $document);

        $updated = $collection->updateOne(['id' => self::HEX_ID], ['$set' => ['name' => 'updated']]);
        $this->assertSame(1, $updated->getModifiedCount());
    }

    public function testDuplicateKeyThrowsDriverException(): void
    {
        $collection = $this->newCollection('duplicate_');
        $collection->insertOne(['_id' => self::HEX_ID]);

        try {
            $collection->insertOne(['_id' => self::HEX_ID]);
            $this->fail('Expected BulkWriteException');
        } catch (BulkWriteException $exception) {
            $this->assertSame(11000, $exception->getCode());
        }
    }

    public function testReadOptionsCannotOverrideTypeMap(): void
    {
        $collection = $this->newCollection('options_');

        $this->expectException(InvalidArgumentException::class);

        $collection->find([], ['typeMap' => ['root' => 'array']]);
    }

    public function testFindOneCannotOverrideCodec(): void
    {
        $collection = $this->newCollection('options_codec_');

        $this->expectException(InvalidArgumentException::class);

        $collection->findOne([], ['codec' => new \stdClass()]);
    }

    public function testStringReturnDocumentIsRejected(): void
    {
        $collection = $this->newCollection('options_return_');

        $this->expectException(InvalidArgumentException::class);

        $collection->findOneAndUpdate(['_id' => self::HEX_ID], ['$set' => ['a' => 1]], ['returnDocument' => 'after']);
    }

    public function testIncludeResultMetadataIsRejected(): void
    {
        $collection = $this->newCollection('options_metadata_');

        $this->expectException(InvalidArgumentException::class);

        $collection->findOneAndUpdate(
            ['_id' => self::HEX_ID],
            ['$set' => ['a' => 1]],
            ['includeResultMetadata' => true]
        );
    }

    public function testFindOneAndDeleteRejectsReturnDocument(): void
    {
        $collection = $this->newCollection('options_delete_');

        $this->expectException(InvalidArgumentException::class);

        $collection->findOneAndDelete(['_id' => self::HEX_ID], ['returnDocument' => 1]);
    }

    public function testModelCachesCollectionsAndPing(): void
    {
        $name = $this->registerCollection('cache_');

        $this->assertTrue(self::$model->ping());
        $this->assertSame(self::$model->collection($name), self::$model->collection($name));
        $this->assertNotSame(
            self::$model->collection($name),
            self::$model->collection($name, self::$database . '_other')
        );
        $this->assertSame($name, self::$model->collection($name)->getName());
        $this->assertStringEndsWith('.' . $name, self::$model->collection($name)->getNamespace());
        $this->assertSame($name, self::$model->{$name}->getName());
    }

    public function testDebugInfoDoesNotExposeUri(): void
    {
        $debug = self::$model->__debugInfo();

        $this->assertArrayNotHasKey('uri', $debug);
        $this->assertSame(self::$database, $debug['database']);
        $this->assertSame('_id', $debug['id_key']);
        $this->assertTrue($debug['object_id']);
    }

    private function newCollection(string $suffix = ''): MongodbCollection
    {
        return self::$model->collection($this->registerCollection($suffix));
    }

    private function registerCollection(string $suffix = ''): string
    {
        $name = 'phpunit_mongodb_' . $suffix . bin2hex(random_bytes(4));
        $this->collections[] = $name;

        return $name;
    }
}
