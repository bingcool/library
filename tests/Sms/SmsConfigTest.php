<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Sms\Config\SmsConfig;
use Swoolefy\Library\Sms\Exception\InvalidConfigException;

final class SmsConfigTest extends TestCase
{
    public function testFromArrayStripsOuterSmsKey(): void
    {
        $config = SmsConfig::fromArray([
            'sms' => [
                'default_gateway' => 'aliyun',
                'gateways' => [
                    'aliyun' => ['gateway' => 'aliyun', 'sign_name' => 'S'],
                    'log' => ['gateway' => 'log'],
                ],
            ],
        ]);

        self::assertSame('aliyun', $config->defaultGateway());
        self::assertTrue($config->hasGateway('log'));
        self::assertFalse($config->hasGateway('nope'));
        self::assertSame(['gateway' => 'log'], $config->gateway('log'));
        self::assertSame([], $config->gateway('nope'));
    }

    public function testSupportsTopLevelConfigWithoutWrapper(): void
    {
        $config = SmsConfig::fromArray([
            'gateways' => ['tencent' => ['gateway' => 'tencent']],
        ]);

        // 无 default_gateway 时取第一个键
        self::assertSame('tencent', $config->defaultGateway());
    }

    public function testDefaultGatewayFallsBackToLogWhenEmpty(): void
    {
        $config = SmsConfig::fromArray(['gateways' => []]);
        self::assertSame('log', $config->defaultGateway());
    }

    public function testVerificationBlockReturnedAsArray(): void
    {
        $config = SmsConfig::fromArray([
            'verification' => ['ttl' => 120, 'max_verify' => 3],
        ]);
        self::assertSame(
            ['ttl' => 120, 'max_verify' => 3, 'default_region' => 'CN'],
            $config->verification()
        );
    }

    public function testDefaultRegionDefaultsToMainland(): void
    {
        self::assertSame('CN', SmsConfig::fromArray([])->defaultRegion());
    }

    public function testDefaultRegionAcceptsNamesAndCountryCodes(): void
    {
        self::assertSame('HK', SmsConfig::fromArray(['default_region' => 'hk'])->defaultRegion());
        self::assertSame('MO', SmsConfig::fromArray(['default_region' => '+853'])->defaultRegion());
        self::assertSame('TW', SmsConfig::fromArray(['default_region' => '886'])->defaultRegion());
        self::assertSame('CN', SmsConfig::fromArray(['default_region' => ''])->defaultRegion());
    }

    public function testVerificationInheritsTopLevelDefaultRegion(): void
    {
        $config = SmsConfig::fromArray([
            'default_region' => 'HK',
            'verification' => ['ttl' => 60],
        ]);

        self::assertSame('HK', $config->verification()['default_region']);
    }

    public function testVerificationKeepsItsOwnDefaultRegion(): void
    {
        $config = SmsConfig::fromArray([
            'default_region' => 'HK',
            'verification' => ['default_region' => 'MO'],
        ]);

        self::assertSame('MO', $config->verification()['default_region']);
    }

    public function testUnsupportedDefaultRegionRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        SmsConfig::fromArray(['default_region' => 'US'])->defaultRegion();
    }

    public function testRedisComponentDefaultsToCachePhpComponent(): void
    {
        $config = SmsConfig::fromArray([
            'default_gateway' => 'aliyun',
            'gateways' => ['aliyun' => ['gateway' => 'aliyun']],
        ]);

        self::assertSame('cache', $config->redisComponent());
    }

    public function testRedisComponentReadsConfiguredName(): void
    {
        $config = SmsConfig::fromArray([
            'sms' => [
                'default_gateway' => 'aliyun',
                'redis_component' => 'session_cache',
            ],
        ]);

        self::assertSame('session_cache', $config->redisComponent());
    }
}
