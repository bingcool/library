<?php

namespace Swoolefy\Library\Tests\Uuid;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Uuid\LuaScripts;
use Swoolefy\Library\Uuid\UuidIncrement;
use Swoolefy\Library\Uuid\UuidManager;

/**
 * 批量区间右闭、步长上限、TIME 微秒补零。
 */
class UuidRangeFixTest extends TestCase
{
    protected function tearDown(): void
    {
        $property = new \ReflectionProperty(UuidManager::class, 'poolIdsQueue');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    public function testExpandRangeIsInclusiveOnTheRightAndExcludesPreviousId(): void
    {
        $single = LuaScripts::expandRange(5001, 1);
        $batch = LuaScripts::expandRange(5011, 10);

        $this->assertSame([5001], $single);
        $this->assertSame(range(5002, 5011), $batch);
        $this->assertSame([], array_intersect($single, $batch));
        $this->assertSame(5011, $batch[count($batch) - 1]);
    }

    public function testExpandRangeClampsCountToMaxIncr(): void
    {
        $maxId = 1000000 + LuaScripts::MAX_INCR;
        $ids = LuaScripts::expandRange($maxId, LuaScripts::MAX_INCR + 500);

        $this->assertCount(LuaScripts::MAX_INCR, $ids);
        $this->assertSame(1000001, $ids[0]);
        $this->assertSame($maxId, $ids[LuaScripts::MAX_INCR - 1]);
    }

    public function testPreBatchDoesNotOverlapPreviousSingleId(): void
    {
        $redis = new FakeUuidRedis();
        $redis->evalQueue = [
            ['5000', 1],
            ['5000', 11],
        ];
        $uuid = new RangeIncrementHarness($redis, 'uuid-key');

        $single = $uuid->callGenerateId(1);
        $uuid->preBatchGenerateIds(10);
        $pool = $uuid->poolIds();

        $this->assertSame(5001, $single);
        $this->assertSame(range(5002, 5011), $pool);
        $this->assertNotContains($single, $pool);
    }

    public function testGetIncrIdsIncludesMaxAndExcludesPrevious(): void
    {
        $redis = new FakeUuidRedis();
        $redis->evalQueue = [['8000', 10]];
        $manager = new RangeManagerHarness($redis, 'uuid-key');

        $ids = $manager->getIncrIds(10);

        $this->assertSame(range(8001, 8010), $ids);
    }

    public function testGetIncrIdsClampsStepSentToRedis(): void
    {
        $redis = new FakeUuidRedis();
        $redis->evalQueue = [['9000', LuaScripts::MAX_INCR]];
        $manager = new RangeManagerHarness($redis, 'uuid-key');

        $ids = $manager->getIncrIds(LuaScripts::MAX_INCR + 10);

        $this->assertSame(LuaScripts::MAX_INCR, $redis->evalArgs[0][1][1]);
        $this->assertCount(LuaScripts::MAX_INCR, $ids);
        $this->assertSame(9001, $ids[0]);
        $this->assertSame(9000 + LuaScripts::MAX_INCR, $ids[LuaScripts::MAX_INCR - 1]);
    }

    public function testTimeStampTtlIsNotShorterThanIncrTtl(): void
    {
        $redis = $this->connectRedis();
        $lua = LuaScripts::getUuidLuaScript();
        $key = 'uuid_ts_ttl_' . getmypid();
        $stampKey = $key . '_time_stamp';
        $ttl = 30;
        $redis->del($key, $stampKey);

        try {
            $this->evalUntilId($redis, $lua, $key, 1, $ttl);
            $incrTtl = $redis->ttl($key);
            $stampTtl = $redis->ttl($stampKey);
            $this->assertGreaterThan(0, $incrTtl);
            $this->assertGreaterThanOrEqual($incrTtl, $stampTtl);

            $this->evalUntilId($redis, $lua, $key, 1, $ttl);
            $this->assertGreaterThan(0, $redis->ttl($stampKey));
            $this->assertGreaterThanOrEqual($redis->ttl($key), $redis->ttl($stampKey));
        } finally {
            $redis->del($key, $stampKey);
        }
    }

    public function testLuaPadsMicrosecondsAndClampsStep(): void
    {
        $redis = $this->connectRedis();

        $lua = LuaScripts::getUuidLuaScript();
        $key = 'uuid_range_fix_' . getmypid();
        $redis->del($key, $key . '_time_stamp');

        try {
            $shortPrefix = 0;
            for ($i = 0; $i < 400; $i++) {
                $row = $this->evalUntilId($redis, $lua, $key);
                if (strlen((string)$row[0]) !== 19) {
                    $shortPrefix++;
                }
            }
            $this->assertSame(0, $shortPrefix);

            $redis->del($key, $key . '_time_stamp');
            $row = $this->evalUntilId($redis, $lua, $key, 1200000);
            $this->assertSame(LuaScripts::MAX_INCR, (int)$row[1]);

            $prefix = (string)$row[0];
            $maxId = (string)((int)$prefix + (int)$row[1]);
            $this->assertSame(substr($prefix, 0, 13), substr($maxId, 0, 13));
        } finally {
            $redis->del($key, $key . '_time_stamp');
        }
    }

    private function connectRedis(): \Redis
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis is required');
        }

        $redis = new \Redis();
        try {
            $redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable $e) {
            $this->markTestSkipped('redis is not available: ' . $e->getMessage());
        }

        return $redis;
    }

    /**
     * 同一毫秒脚本会返回空，调用方本来就会重试。
     *
     * @return array
     */
    private function evalUntilId(\Redis $redis, string $lua, string $key, int $step = 1, int $ttl = 30): array
    {
        for ($i = 0; $i < 30; $i++) {
            $row = $redis->eval($lua, [$key, $step, $ttl], 1);
            if (!empty($row)) {
                return $row;
            }
            usleep(1000);
        }

        $this->fail('lua script returned empty');
    }
}

final class RangeIncrementHarness extends UuidIncrement
{
    protected function waitBeforeRetry(int $microseconds): void
    {
    }

    public function callGenerateId(?int $count = 1): ?int
    {
        return $this->generateId($count);
    }

    public function poolIds(): array
    {
        return $this->poolIds;
    }
}

final class RangeManagerHarness extends UuidManager
{
    protected function waitBeforeRetry(float $seconds): void
    {
    }
}
