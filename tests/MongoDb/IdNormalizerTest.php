<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\MongoDb;

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Mongodb\Support\IdNormalizer;

final class IdNormalizerTest extends TestCase
{
    private const HEX_ID = '507f1f77bcf86cd799439011';

    public function testFilterConvertsHexStringToObjectId(): void
    {
        $normalizer = new IdNormalizer();
        $filter = $normalizer->normalizeFilter(['_id' => self::HEX_ID]);

        $this->assertInstanceOf(ObjectId::class, $filter['_id']);
        $this->assertSame(self::HEX_ID, (string) $filter['_id']);
    }

    public function testFilterKeepsNonHexAndShortStrings(): void
    {
        $normalizer = new IdNormalizer();

        $this->assertSame(['_id' => 'user-1'], $normalizer->normalizeFilter(['_id' => 'user-1']));
        $this->assertSame(
            ['_id' => str_repeat('z', 24)],
            $normalizer->normalizeFilter(['_id' => str_repeat('z', 24)])
        );
        $this->assertSame(['_id' => 42], $normalizer->normalizeFilter(['_id' => 42]));
    }

    public function testFilterPreservesObjectIdInstance(): void
    {
        $normalizer = new IdNormalizer();
        $objectId = new ObjectId(self::HEX_ID);

        $filter = $normalizer->normalizeFilter(['_id' => $objectId]);

        $this->assertSame($objectId, $filter['_id']);
    }

    public function testFilterConvertsSupportedOperators(): void
    {
        $normalizer = new IdNormalizer();
        $filter = $normalizer->normalizeFilter([
            '_id' => [
                '$eq' => self::HEX_ID,
                '$ne' => self::HEX_ID,
                '$in' => [self::HEX_ID, 'user-1'],
                '$nin' => [self::HEX_ID],
                '$all' => [self::HEX_ID],
            ],
        ]);

        $this->assertInstanceOf(ObjectId::class, $filter['_id']['$eq']);
        $this->assertInstanceOf(ObjectId::class, $filter['_id']['$ne']);
        $this->assertInstanceOf(ObjectId::class, $filter['_id']['$in'][0]);
        $this->assertSame('user-1', $filter['_id']['$in'][1]);
        $this->assertInstanceOf(ObjectId::class, $filter['_id']['$nin'][0]);
        $this->assertInstanceOf(ObjectId::class, $filter['_id']['$all'][0]);
    }

    public function testFilterLeavesUnsupportedOperatorsAlone(): void
    {
        $normalizer = new IdNormalizer();
        $filter = $normalizer->normalizeFilter([
            '_id' => [
                '$exists' => true,
                '$type' => 'string',
                '$not' => ['$eq' => self::HEX_ID],
            ],
        ]);

        $this->assertSame(
            [
                '$exists' => true,
                '$type' => 'string',
                '$not' => ['$eq' => self::HEX_ID],
            ],
            $filter['_id']
        );
    }

    public function testFilterRecursesTopLevelLogicalOperators(): void
    {
        $normalizer = new IdNormalizer();
        $filter = $normalizer->normalizeFilter([
            '$or' => [
                ['_id' => self::HEX_ID],
                ['_id' => 'user-1'],
            ],
            '$and' => [
                ['_id' => ['$in' => [self::HEX_ID]]],
            ],
            '$nor' => [
                ['_id' => self::HEX_ID],
            ],
        ]);

        $this->assertInstanceOf(ObjectId::class, $filter['$or'][0]['_id']);
        $this->assertSame('user-1', $filter['$or'][1]['_id']);
        $this->assertInstanceOf(ObjectId::class, $filter['$and'][0]['_id']['$in'][0]);
        $this->assertInstanceOf(ObjectId::class, $filter['$nor'][0]['_id']);
    }

    public function testFilterDoesNotTouchNestedOrCompoundIds(): void
    {
        $normalizer = new IdNormalizer();

        $nested = $normalizer->normalizeFilter(['meta' => ['_id' => self::HEX_ID]]);
        $this->assertSame(['meta' => ['_id' => self::HEX_ID]], $nested);

        $compound = $normalizer->normalizeFilter(['_id' => ['tenant' => self::HEX_ID]]);
        $this->assertSame(['_id' => ['tenant' => self::HEX_ID]], $compound);
    }

    public function testInsertDocumentConvertsIdWithoutMutatingInput(): void
    {
        $normalizer = new IdNormalizer();
        $document = ['_id' => self::HEX_ID, 'name' => 'demo'];

        $normalized = $normalizer->normalizeInsertDocument($document);

        $this->assertInstanceOf(ObjectId::class, $normalized['_id']);
        $this->assertSame(self::HEX_ID, $document['_id']);
        $this->assertSame('demo', $normalized['name']);
    }

    public function testInsertDocumentPreservesNonHexId(): void
    {
        $normalizer = new IdNormalizer();

        $this->assertSame(['_id' => 'user-1'], $normalizer->normalizeInsertDocument(['_id' => 'user-1']));
        $this->assertSame(['_id' => 42], $normalizer->normalizeInsertDocument(['_id' => 42]));
    }

    public function testReadDocumentConvertsObjectIdToString(): void
    {
        $normalizer = new IdNormalizer();

        $document = $normalizer->normalizeDocument(['_id' => new ObjectId(self::HEX_ID), 'name' => 'demo']);

        $this->assertSame(['_id' => self::HEX_ID, 'name' => 'demo'], $document);
    }

    public function testReadDocumentPreservesOtherIdTypes(): void
    {
        $normalizer = new IdNormalizer();

        $this->assertSame(['_id' => 42], $normalizer->normalizeDocument(['_id' => 42]));
        $this->assertSame(['_id' => 'user-1'], $normalizer->normalizeDocument(['_id' => 'user-1']));
        $this->assertSame(
            ['_id' => ['tenant' => 1]],
            $normalizer->normalizeDocument(['_id' => ['tenant' => 1]])
        );
    }

    public function testObjectIdDisabledLeavesBothDirectionsUntouched(): void
    {
        $normalizer = new IdNormalizer('_id', false);
        $objectId = new ObjectId(self::HEX_ID);

        $this->assertSame(['_id' => self::HEX_ID], $normalizer->normalizeFilter(['_id' => self::HEX_ID]));
        $this->assertSame(['_id' => $objectId], $normalizer->normalizeDocument(['_id' => $objectId]));
        $this->assertSame(['_id' => self::HEX_ID], $normalizer->normalizeInsertDocument(['_id' => self::HEX_ID]));
    }

    public function testIdKeyAliasIsMappedInFilter(): void
    {
        $normalizer = new IdNormalizer('id');

        $filter = $normalizer->normalizeFilter(['id' => self::HEX_ID, 'name' => 'demo']);
        $this->assertInstanceOf(ObjectId::class, $filter['_id']);
        $this->assertArrayNotHasKey('id', $filter);

        $precedence = $normalizer->normalizeFilter([
            '_id' => self::HEX_ID,
            'id' => 'ignored',
        ]);
        $this->assertInstanceOf(ObjectId::class, $precedence['_id']);
        $this->assertArrayNotHasKey('id', $precedence);
    }

    public function testIdKeyAliasIsMappedInInsertDocument(): void
    {
        $normalizer = new IdNormalizer('id');

        $document = $normalizer->normalizeInsertDocument(['id' => self::HEX_ID, 'name' => 'demo']);
        $this->assertInstanceOf(ObjectId::class, $document['_id']);
        $this->assertArrayNotHasKey('id', $document);
    }

    public function testInsertDocumentRejectsAmbiguousIdKeys(): void
    {
        $normalizer = new IdNormalizer('id');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/both "_id" and "id"/');

        $normalizer->normalizeInsertDocument(['_id' => self::HEX_ID, 'id' => 'other']);
    }

    public function testIdKeyAliasIsMappedInReadDocument(): void
    {
        $normalizer = new IdNormalizer('id');

        $document = $normalizer->normalizeDocument(['_id' => new ObjectId(self::HEX_ID), 'name' => 'demo']);
        $this->assertEquals(['id' => self::HEX_ID, 'name' => 'demo'], $document);
        $this->assertArrayNotHasKey('_id', $document);
    }

    public function testReadDocumentKeepsBusinessIdField(): void
    {
        $normalizer = new IdNormalizer('id');
        $objectId = new ObjectId(self::HEX_ID);

        $document = $normalizer->normalizeDocument(['_id' => $objectId, 'id' => 'business-id']);
        $this->assertSame('business-id', $document['id']);
        $this->assertSame(self::HEX_ID, $document['_id']);

        $nullField = $normalizer->normalizeDocument(['_id' => $objectId, 'id' => null]);
        $this->assertSame(self::HEX_ID, $nullField['id']);
        $this->assertArrayNotHasKey('_id', $nullField);
    }

    public function testIdKeyAliasIsMappedInsideLogicalOperators(): void
    {
        $normalizer = new IdNormalizer('id');

        $filter = $normalizer->normalizeFilter([
            '$or' => [
                ['id' => self::HEX_ID],
            ],
        ]);

        $this->assertInstanceOf(ObjectId::class, $filter['$or'][0]['_id']);
        $this->assertArrayNotHasKey('id', $filter['$or'][0]);
    }
}
