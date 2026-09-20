<?php

namespace Swoolefy\Library\Tests\Redis;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Redis\RedisCluster;
use Swoolefy\Library\Redis\Predis;

class RedisReconnectRetryTest extends TestCase
{
    public function testGetIsReplayedOnceAfterConnectionException(): void
    {
        $native = new FakeNativeRedis();
        $native->failTimes = 1;
        $native->result = 'value';
        $redis = new RedisRetryStub($native);

        $this->assertSame('value', $redis->get('k'));
        $this->assertSame(1, $redis->reconnects);
        $this->assertSame(['get', 'get'], array_column(
            array_filter($native->calls, static fn($call) => $call['method'] === 'get'),
            'method'
        ));
    }

    public function testSmembersIsReplayedOnce(): void
    {
        $native = new FakeNativeRedis();
        $native->failTimes = 1;
        $native->result = ['a'];
        $redis = new RedisRetryStub($native);

        $this->assertSame(['a'], $redis->sMembers('set'));
        $this->assertCount(2, array_filter($native->calls, static fn($call) => $call['method'] === 'sMembers'));
    }

    public function testIncrIsNotReplayed(): void
    {
        $native = new FakeNativeRedis();
        $native->failTimes = 1;
        $redis = new RedisRetryStub($native);

        $this->expectException(\RedisException::class);
        try {
            $redis->incr('counter');
        } finally {
            $this->assertSame(1, $redis->reconnects);
            $this->assertCount(1, array_filter($native->calls, static fn($call) => $call['method'] === 'incr'));
        }
    }

    public function testEvalIsNotReplayed(): void
    {
        $native = new FakeNativeRedis();
        $native->failTimes = 1;
        $redis = new RedisRetryStub($native);

        $this->expectException(\RedisException::class);
        try {
            $redis->eval('return 1', [], 0);
        } finally {
            $this->assertCount(1, array_filter($native->calls, static fn($call) => $call['method'] === 'eval'));
        }
    }

    public function testSetAndGeoRadiusAreNotReplayed(): void
    {
        $native = new FakeNativeRedis();
        $native->failTimes = 1;
        $redis = new RedisRetryStub($native);

        try {
            $redis->set('k', 'v', ['NX', 'EX' => 10]);
            $this->fail('set should throw');
        } catch (\RedisException $e) {
            $this->assertCount(1, array_filter($native->calls, static fn($call) => $call['method'] === 'set'));
        }

        $native->calls = [];
        $native->failTimes = 1;
        try {
            $redis->geoRadius('geo', 0, 0, 1, 'km');
            $this->fail('geoRadius should throw');
        } catch (\RedisException $e) {
            $this->assertCount(1, array_filter($native->calls, static fn($call) => $call['method'] === 'geoRadius'));
        }
    }

    public function testRawCommandRespectsRealRedisCommand(): void
    {
        $native = new FakeNativeRedis();
        $native->failTimes = 1;
        $redis = new RedisRetryStub($native);

        try {
            $redis->rawCommand('INCR', 'k');
            $this->fail('raw INCR should throw');
        } catch (\RedisException $e) {
            $this->assertCount(1, array_filter($native->calls, static fn($call) => $call['method'] === 'rawCommand'));
        }

        $native->calls = [];
        $native->failTimes = 1;
        $native->result = 'v';
        $this->assertSame('v', $redis->rawCommand('GET', 'k'));
        $this->assertCount(2, array_filter($native->calls, static fn($call) => $call['method'] === 'rawCommand'));
    }

    public function testWrongTypeDoesNotReconnect(): void
    {
        $native = new FakeNativeRedis();
        $native->failTimes = 1;
        $native->failWith = new \RedisException('WRONGTYPE Operation against a key holding the wrong kind of value');
        $redis = new RedisRetryStub($native);

        $this->expectException(\RedisException::class);
        try {
            $redis->get('k');
        } finally {
            $this->assertSame(0, $redis->reconnects);
            $this->assertCount(1, $native->calls);
        }
    }

    public function testMultiContextDoesNotReplayGet(): void
    {
        $native = new FakeNativeRedis();
        $redis = new RedisRetryStub($native);
        $redis->multi();
        $native->failTimes = 1;

        $this->expectException(\RedisException::class);
        try {
            $redis->get('k');
        } finally {
            $this->assertSame(1, $redis->reconnects);
            $this->assertCount(1, array_filter($native->calls, static fn($call) => $call['method'] === 'get'));
        }
    }

    public function testReconnectRestoresSelectedDatabaseBeforeReplay(): void
    {
        $native = new FakeNativeRedis();
        $redis = new RedisRetryStub($native);
        $redis->select(2);
        $native->failTimes = 1;
        $native->result = 'v';

        $this->assertSame('v', $redis->get('k'));
        $this->assertSame(1, $redis->reconnects);

        $selects = array_values(array_filter($native->calls, static fn($call) => $call['method'] === 'select'));
        $this->assertGreaterThanOrEqual(2, count($selects));
        $this->assertSame([2], $selects[array_key_last($selects)]['args']);
    }

    public function testReplayFailureDoesNotTryAThirdTime(): void
    {
        $native = new FakeNativeRedis();
        $native->failTimes = 2;
        $redis = new RedisRetryStub($native);

        $this->expectException(\RedisException::class);
        try {
            $redis->get('k');
        } finally {
            $this->assertSame(2, $redis->reconnects);
            $this->assertCount(2, array_filter($native->calls, static fn($call) => $call['method'] === 'get'));
        }
    }

    public function testRetryDisabledNeverReplays(): void
    {
        $native = new FakeNativeRedis();
        $native->failTimes = 1;
        $redis = new RedisRetryStub($native);
        $redis->setRetryOptions(['enabled' => false, 'delay' => 0]);

        $this->expectException(\RedisException::class);
        try {
            $redis->get('k');
        } finally {
            $this->assertSame(1, $redis->reconnects);
            $this->assertCount(1, array_filter($native->calls, static fn($call) => $call['method'] === 'get'));
        }
    }

    public function testExtraCommandsCanEnableSetRetry(): void
    {
        $native = new FakeNativeRedis();
        $native->failTimes = 1;
        $native->result = true;
        $redis = new RedisRetryStub($native);
        $redis->setRetryOptions(['extra_commands' => ['SET'], 'delay' => 0]);

        $this->assertTrue($redis->set('k', 'v'));
        $this->assertCount(2, array_filter($native->calls, static fn($call) => $call['method'] === 'set'));
    }

    public function testPredisAndClusterShareTheSamePolicyClass(): void
    {
        $this->assertSame(
            \Swoolefy\Library\Redis\RedisRetryPolicy::class,
            (new \ReflectionClass(Predis::class))->getParentClass()->getName() === 'Swoolefy\\Library\\Redis\\RedisConnection'
                ? \Swoolefy\Library\Redis\RedisRetryPolicy::class
                : null
        );
        $this->assertTrue(is_subclass_of(Predis::class, \Swoolefy\Library\Redis\RedisConnection::class));
        $this->assertTrue(is_subclass_of(RedisCluster::class, \Swoolefy\Library\Redis\RedisConnection::class));
    }
}
