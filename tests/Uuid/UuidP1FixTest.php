<?php

namespace Swoolefy\Library\Tests\Uuid;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel;
use Swoolefy\Library\Uuid\UuidIncrement;
use Swoolefy\Library\Uuid\UuidManager;

class UuidP1FixTest extends TestCase
{
    protected function tearDown(): void
    {
        if (class_exists(Channel::class)) {
            UuidManagerHarness::resetPool(null);
        }
    }

    public function testIncrementRetryTimesIsNotConsumedOnTheInstance(): void
    {
        $redis = new FakeUuidRedis();
        $uuid = new UuidIncrementHarness($redis, 'uuid-key');

        $this->assertNull($uuid->callGenerateId(1));
        $this->assertSame(3, $uuid->retryTimesValue());
        $this->assertSame(3, $redis->evalCalls);

        $this->assertNull($uuid->callGenerateId(1));
        $this->assertSame(3, $uuid->retryTimesValue());
        $this->assertSame(6, $redis->evalCalls);
    }

    public function testIncrementPartialFailureStillKeepsDefaultRetryTimes(): void
    {
        $redis = new FakeUuidRedis();
        $redis->evalQueue = [[], ['1000', 5]];
        $uuid = new UuidIncrementHarness($redis, 'uuid-key');

        $this->assertSame(1005, $uuid->callGenerateId(1));
        $this->assertSame(3, $uuid->retryTimesValue());
        $this->assertSame(2, $redis->evalCalls);
    }

    public function testPreBatchGenerateIdsDoesNotUseNullMaxId(): void
    {
        $redis = new FakeUuidRedis();
        $uuid = new UuidIncrementHarness($redis, 'uuid-key');

        $this->assertTrue($uuid->preBatchGenerateIds(10));
        $this->assertSame([], $uuid->poolIds());
        $this->assertNull($uuid->getIncrId());
    }

    public function testPreBatchGenerateIdsFillsPoolOnSuccess(): void
    {
        $redis = new FakeUuidRedis();
        $redis->evalQueue = [['1000', 10]];
        $uuid = new UuidIncrementHarness($redis, 'uuid-key');

        $this->assertTrue($uuid->preBatchGenerateIds(10));
        $this->assertCount(10, $uuid->poolIds());
        $this->assertSame(1000, $uuid->getIncrId());
        $this->assertSame(1001, $uuid->getIncrId());
    }

    public function testManagerRetryTimesIsNotConsumedOnTheInstance(): void
    {
        $redis = new FakeUuidRedis();
        $manager = new UuidManagerHarness($redis, 'uuid-key');

        $this->assertNull($manager->callGenerateId(1));
        $this->assertSame(3, $manager->retryTimesValue());
        $this->assertSame(3, $redis->evalCalls);

        $this->assertNull($manager->callGenerateId(1));
        $this->assertSame(3, $manager->retryTimesValue());
        $this->assertSame(6, $redis->evalCalls);
    }

    public function testGetIncrIdsDoesNotSubtractNull(): void
    {
        $redis = new FakeUuidRedis();
        $manager = new UuidManagerHarness($redis, 'uuid-key');

        $ids = $manager->getIncrIds(2);

        $this->assertSame([], $ids);
        $this->assertNull($manager->getOneId());
    }

    public function testGetIncrIdsZeroAndNegativeDoNotHitRedis(): void
    {
        $redis = new FakeUuidRedis();
        $manager = new UuidManagerHarness($redis, 'uuid-key');

        $this->assertSame([], $manager->getIncrIds(0));
        $this->assertSame([], $manager->getIncrIds(-1));
        $this->assertSame(0, $redis->evalCalls);
    }

    public function testChannelPopDoesNotDiscardExtraId(): void
    {
        $this->runInCoroutine(function () {
            $channel = $this->makePoolChannel(['A', 'B', 'C']);
            $redis = new FakeUuidRedis();
            $manager = new UuidManagerHarness($redis, 'uuid-key');

            $this->assertSame(['A'], $manager->getIncrIds(1));
            $this->assertSame(0, $redis->evalCalls);
            $this->assertSame(2, $channel->length());
            $this->assertSame(['B', 'C'], $this->drainChannel($channel));
        });
    }

    public function testChannelPopTwoLeavesTheRest(): void
    {
        $this->runInCoroutine(function () {
            $channel = $this->makePoolChannel(['A', 'B', 'C']);
            $manager = new UuidManagerHarness(new FakeUuidRedis(), 'uuid-key');

            $this->assertSame(['A', 'B'], $manager->getIncrIds(2));
            $this->assertSame(['C'], $this->drainChannel($channel));
        });
    }

    public function testChannelExactlyNumDoesNotPopFurther(): void
    {
        $this->runInCoroutine(function () {
            $channel = $this->makePoolChannel(['A', 'B', 'C']);
            $redis = new FakeUuidRedis();
            $manager = new UuidManagerHarness($redis, 'uuid-key');

            $this->assertSame(['A', 'B', 'C'], $manager->getIncrIds(3));
            $this->assertSame(0, $redis->evalCalls);
            $this->assertSame(0, $channel->length());
        });
    }

    public function testShortChannelDoesNotDropIdsAndGenerateIdFillsRemainder(): void
    {
        $this->runInCoroutine(function () {
            $channel = $this->makePoolChannel(['A', 'B']);
            $redis = new FakeUuidRedis();
            $redis->evalQueue = [['1000', 1]];
            $manager = new UuidManagerHarness($redis, 'uuid-key');

            $this->assertSame(['A', 'B', 1000], $manager->getIncrIds(3));
            $this->assertSame(1, $redis->evalCalls);
            $this->assertSame(0, $channel->length());
        });
    }

    public function testGetIncrIdsZeroDoesNotConsumeChannel(): void
    {
        $this->runInCoroutine(function () {
            $channel = $this->makePoolChannel(['A']);
            $manager = new UuidManagerHarness(new FakeUuidRedis(), 'uuid-key');

            $this->assertSame([], $manager->getIncrIds(0));
            $this->assertSame(1, $channel->length());
        });
    }

    /**
     * Channel 带超时的 pop 必须在协程里执行，否则 Swoole 可能直接中止进程。
     *
     * @param callable $fn
     */
    private function runInCoroutine(callable $fn): void
    {
        if (!class_exists(Channel::class) || !class_exists(\Swoole\Coroutine::class)) {
            $this->markTestSkipped('ext-swoole is required for Channel tests');
        }
        if (\Swoole\Coroutine::getCid() > 0) {
            $fn();
            return;
        }
        \Swoole\Coroutine\run($fn);
    }

    /**
     * @param array $items
     * @return Channel
     */
    private function makePoolChannel(array $items): Channel
    {
        $channel = new Channel(8);
        foreach ($items as $item) {
            $channel->push($item);
        }
        UuidManagerHarness::resetPool($channel);

        return $channel;
    }

    /**
     * @param Channel $channel
     * @return array
     */
    private function drainChannel(Channel $channel): array
    {
        $items = [];
        while ($channel->length() > 0) {
            $item = $channel->pop(0.05);
            if ($item === false) {
                break;
            }
            $items[] = $item;
        }

        return $items;
    }
}

final class UuidIncrementHarness extends UuidIncrement
{
    protected function waitBeforeRetry(int $microseconds): void
    {
    }

    public function callGenerateId(?int $count = 1): ?int
    {
        return $this->generateId($count);
    }

    public function retryTimesValue(): int
    {
        return $this->retryTimes;
    }

    public function poolIds(): array
    {
        return $this->poolIds;
    }
}

final class UuidManagerHarness extends UuidManager
{
    protected function waitBeforeRetry(float $seconds): void
    {
    }

    public function callGenerateId(?int $count = 1): ?int
    {
        return $this->generateId($count);
    }

    public function retryTimesValue(): int
    {
        return $this->retryTimes;
    }

    public static function resetPool(?object $channel = null): void
    {
        $property = new \ReflectionProperty(UuidManager::class, 'poolIdsQueue');
        $property->setAccessible(true);
        $property->setValue(null, $channel);
    }
}
