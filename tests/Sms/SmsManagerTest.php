<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Swoolefy\Library\Sms\Exception\InvalidProviderException;
use Swoolefy\Library\Sms\SmsManager;
use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\Testing\FakeSmsGateway;
use Swoolefy\Library\Sms\ValueObject\SendResult;

/**
 * 管理器编排：默认通道 / 指定通道 / 实例缓存 / 自定义驱动 / 未配置异常 / 脱敏日志。
 */
final class SmsManagerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'sms' => [
                'default_gateway' => 'main',
                'gateways' => [
                    'main' => ['gateway' => 'log'],
                    'backup' => ['gateway' => 'log', 'simulate' => 'failure', 'fail_code' => 'isv.AMOUNT_NOT_ENOUGH'],
                ],
            ],
        ];
    }

    public function testSendUsesDefaultGateway(): void
    {
        $manager = new SmsManager($this->config());
        $result = $manager->send(SmsMessage::make('13800008000', 'login_code', ['code' => '123456']));

        self::assertTrue($result->ok);
        self::assertSame('log', $result->gateway);
        self::assertSame(['13800008000'], $result->successPhones);
    }

    public function testSendViaPicksNamedChannel(): void
    {
        $manager = new SmsManager($this->config());
        $result = $manager->sendVia('backup', SmsMessage::make('13800008000', 'login_code'));

        self::assertFalse($result->ok);
        self::assertSame('isv.AMOUNT_NOT_ENOUGH', $result->code);
    }

    public function testGatewayInstanceIsCachedPerChannel(): void
    {
        $manager = new SmsManager($this->config());
        self::assertSame($manager->gateway('main'), $manager->gateway('main'));
    }

    public function testUnknownChannelThrows(): void
    {
        $this->expectException(InvalidProviderException::class);
        (new SmsManager($this->config()))->gateway('nope');
    }

    public function testCustomDriverResolvedByFactory(): void
    {
        $manager = new SmsManager([
            'sms' => [
                'default_gateway' => 'fakechan',
                'gateways' => ['fakechan' => ['gateway' => 'fake']],
            ],
        ]);

        $fake = new FakeSmsGateway('fake');
        $fake->queue(SendResult::success(['13800008000'], 'fake', 'biz-9'));
        $manager->extendDriver('fake', static fn (array $config, string $name): FakeSmsGateway => $fake);

        $result = $manager->send(SmsMessage::make('13800008000', 'login_code'));

        self::assertTrue($result->ok);
        self::assertSame('fake', $result->gateway);
        self::assertSame('biz-9', $result->bizId);
        self::assertSame(1, $fake->sendCount());
    }

    public function testManagerInjectsTopLevelDefaultRegionIntoGatewayConfig(): void
    {
        $manager = new SmsManager([
            'sms' => [
                'default_region' => 'HK',
                'default_gateway' => 'custom',
                'gateways' => [
                    'custom' => ['gateway' => 'capture'],
                    'override' => ['gateway' => 'capture', 'default_region' => 'MO'],
                ],
            ],
        ]);

        $captured = [];
        $manager->extendDriver('capture', static function (array $config, string $name) use (&$captured): FakeSmsGateway {
            $captured[$name] = $config;

            return new FakeSmsGateway('capture');
        });

        $manager->gateway('custom');
        $manager->gateway('override');

        self::assertSame('HK', $captured['custom']['default_region']);
        self::assertSame('MO', $captured['override']['default_region']);
    }

    public function testLoggerRecordsMaskedPhoneOnly(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        $manager = new SmsManager($this->config(), $logger);
        $manager->send(SmsMessage::make('13800008000', 'login_code', ['code' => '123456']));

        // 发送日志只由 Manager 记录一条，LogGateway 不再重复写
        self::assertCount(1, $logger->records);
        self::assertSame('sms send', $logger->records[0]['message']);
        $context = $logger->records[0]['context'];
        // 号码脱敏，不含完整号码，不含验证码明文
        self::assertSame(['138****8000'], $context['phones']);
        self::assertStringNotContainsString('13800008000', json_encode($context, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('123456', json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}
