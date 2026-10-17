<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Sms\Config\SmsConfig;

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
        self::assertSame(['ttl' => 120, 'max_verify' => 3], $config->verification());
    }
}
