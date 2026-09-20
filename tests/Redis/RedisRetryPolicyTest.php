<?php

namespace Swoolefy\Library\Tests\Redis;

use PHPUnit\Framework\TestCase;
use Predis\Connection\ConnectionException;
use Predis\Response\ServerException;
use Swoolefy\Library\Redis\RedisRetryPolicy;

class RedisRetryPolicyTest extends TestCase
{
    public function testDefaultReadCommandsAreRetryable(): void
    {
        $policy = new RedisRetryPolicy();

        $this->assertTrue($policy->shouldRetry('GET'));
        $this->assertTrue($policy->shouldRetry('hGet'));
        $this->assertTrue($policy->shouldRetry('HGETALL'));
        $this->assertTrue($policy->shouldRetry('SMEMBERS'));
        $this->assertTrue($policy->shouldRetry('geoRadiusRo'));
        $this->assertTrue($policy->shouldRetry('PING'));
    }

    public function testWriteAndUnknownCommandsAreNotRetryable(): void
    {
        $policy = new RedisRetryPolicy();

        $this->assertFalse($policy->shouldRetry('INCR'));
        $this->assertFalse($policy->shouldRetry('SET'));
        $this->assertFalse($policy->shouldRetry('EVAL'));
        $this->assertFalse($policy->shouldRetry('EVALSHA'));
        $this->assertFalse($policy->shouldRetry('GEORADIUS'));
        $this->assertFalse($policy->shouldRetry('LPUSH'));
        $this->assertFalse($policy->shouldRetry('JSON.GET'));
        $this->assertFalse($policy->shouldRetry('SCAN'));
        $this->assertFalse($policy->shouldRetry(''));
    }

    public function testTransactionalContextNeverRetries(): void
    {
        $policy = new RedisRetryPolicy();

        $this->assertFalse($policy->shouldRetry('GET', true));
    }

    public function testEnabledFalseDisablesAllRetry(): void
    {
        $policy = new RedisRetryPolicy(['enabled' => false]);

        $this->assertFalse($policy->isEnabled());
        $this->assertFalse($policy->shouldRetry('GET'));
    }

    public function testCommandsOptionReplacesDefaultWhitelist(): void
    {
        $policy = new RedisRetryPolicy([
            'commands' => ['GET'],
        ]);

        $this->assertTrue($policy->shouldRetry('GET'));
        $this->assertFalse($policy->shouldRetry('SMEMBERS'));
    }

    public function testExtraCommandsAreMerged(): void
    {
        $policy = new RedisRetryPolicy([
            'extra_commands' => ['SET'],
        ]);

        $this->assertTrue($policy->shouldRetry('SET'));
        $this->assertTrue($policy->shouldRetry('GET'));
    }

    public function testResolveCommandNormalizesRawCommand(): void
    {
        $this->assertSame('GET', RedisRetryPolicy::resolveCommand('rawCommand', ['GET', 'k']));
        $this->assertSame('INCR', RedisRetryPolicy::resolveCommand('executeRaw', [['INCR', 'k']]));
        $this->assertSame('HGET', RedisRetryPolicy::resolveCommand('hGet', ['hash', 'f']));
        $this->assertSame('', RedisRetryPolicy::resolveCommand('rawCommand', [null]));
    }

    public function testRawWriteCommandIsNotRetryable(): void
    {
        $policy = new RedisRetryPolicy();
        $command = RedisRetryPolicy::resolveCommand('rawCommand', ['INCR', 'k']);

        $this->assertFalse($policy->shouldRetry($command));
        $this->assertTrue($policy->shouldRetry(RedisRetryPolicy::resolveCommand('rawCommand', ['GET', 'k'])));
    }

    public function testPhpRedisConnectionExceptionDetection(): void
    {
        $this->assertTrue(RedisRetryPolicy::isPhpRedisConnectionException(
            new \RedisException('read error on connection')
        ));
        $this->assertTrue(RedisRetryPolicy::isPhpRedisConnectionException(
            new \RedisException('Redis server went away')
        ));
        $this->assertFalse(RedisRetryPolicy::isPhpRedisConnectionException(
            new \RedisException('WRONGTYPE Operation against a key holding the wrong kind of value')
        ));
        $this->assertFalse(RedisRetryPolicy::isPhpRedisConnectionException(
            new \RuntimeException('read error on connection')
        ));
    }

    public function testPredisServerExceptionIsNotConnectionLoss(): void
    {
        if (!class_exists(ServerException::class)) {
            $this->markTestSkipped('predis is not installed');
        }

        $exception = $this->getMockBuilder(ServerException::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->assertFalse(RedisRetryPolicy::isPredisConnectionException($exception));
    }

    public function testPredisConnectionExceptionIsConnectionLoss(): void
    {
        if (!class_exists(ConnectionException::class)) {
            $this->markTestSkipped('predis is not installed');
        }

        $exception = $this->getMockBuilder(ConnectionException::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->assertTrue(RedisRetryPolicy::isPredisConnectionException($exception));
    }

    public function testMaxTimesIsCapped(): void
    {
        $policy = new RedisRetryPolicy(['max_times' => 99]);
        $this->assertSame(3, $policy->getMaxTimes());

        $policy->setOptions(['max_times' => -1, 'delay' => 0]);
        $this->assertSame(0, $policy->getMaxTimes());
        $this->assertSame(0.0, $policy->getDelay());
    }
}
