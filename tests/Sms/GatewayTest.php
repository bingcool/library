<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Sms\Exception\InvalidProviderException;
use Swoolefy\Library\Sms\Exception\MissingSdkException;
use Swoolefy\Library\Sms\Gateway\AliyunGateway;
use Swoolefy\Library\Sms\Gateway\LogGateway;
use Swoolefy\Library\Sms\Gateway\TencentGateway;
use Swoolefy\Library\Sms\SmsFactory;
use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\Support\SdkGuard;
use Swoolefy\Library\Sms\Testing\FakeSmsGateway;

/**
 * 网关层：LogGateway 成功 / 失败 / 部分失败模拟，厂商网关凭证校验与 Client 构造。
 *
 * 请求组装 / 响应映射另见 SmsFixTest、ValueObjectTest。
 */
final class GatewayTest extends TestCase
{
    public function testLogGatewayDefaultSuccess(): void
    {
        $gw = new LogGateway(['gateway' => 'log']);
        $result = $gw->send(SmsMessage::make('13800008000', 'login_code', ['code' => '123456']));

        self::assertTrue($result->ok);
        self::assertSame(['13800008000'], $result->successPhones);
        self::assertCount(1, $gw->sentMessages());
    }

    public function testLogGatewaySimulateFailure(): void
    {
        $gw = new LogGateway(['gateway' => 'log', 'simulate' => 'failure', 'fail_code' => 'isv.BUSINESS_LIMIT_CONTROL']);
        $result = $gw->send(SmsMessage::make('13800008000', 'login_code'));

        self::assertFalse($result->ok);
        self::assertSame('isv.BUSINESS_LIMIT_CONTROL', $result->code);
    }

    public function testLogGatewaySimulatePartial(): void
    {
        $gw = new LogGateway(['gateway' => 'log', 'simulate' => 'partial']);
        $result = $gw->send(SmsMessage::make(['13800008000', '13900009000', '13700007000'], 'login_code'));

        self::assertFalse($result->ok);
        self::assertSame(['13800008000', '13700007000'], $result->successPhones);
        self::assertCount(1, $result->failedPhones);
    }

    public function testLogGatewayTemplateCodeAliasAndRaw(): void
    {
        $gw = new LogGateway(['templates' => ['login_code' => 'SMS_1']]);
        self::assertSame('SMS_1', $gw->templateCode('login_code'));
        // 非别名按真实 code 原样返回
        self::assertSame('SMS_999', $gw->templateCode('SMS_999'));
    }

    public function testFactoryRoutesLogGateway(): void
    {
        $factory = new SmsFactory();
        $gw = $factory->make('log', ['gateway' => 'log']);
        self::assertInstanceOf(LogGateway::class, $gw);
    }

    public function testFactoryRejectsUnknownGateway(): void
    {
        $this->expectException(InvalidProviderException::class);
        (new SmsFactory())->make('x', ['gateway' => 'nonexistent']);
    }

    public function testFactoryRejectsMissingGatewayField(): void
    {
        $this->expectException(InvalidProviderException::class);
        (new SmsFactory())->make('x', []);
    }

    public function testAliyunRequiresSignName(): void
    {
        $this->expectException(InvalidProviderException::class);
        new AliyunGateway(['access_key' => 'a', 'access_secret' => 'b']);
    }

    public function testAliyunBuildsClientFromConfig(): void
    {
        $gateway = new AliyunGateway([
            'gateway' => 'aliyun',
            'sign_name' => 'S',
            'access_key' => 'a',
            'access_secret' => 'b',
        ]);

        self::assertSame('aliyun', $gateway->name());
    }

    public function testTencentBuildsClientFromConfig(): void
    {
        $gateway = new TencentGateway([
            'gateway' => 'tencent',
            'sign_name' => 'S',
            'secret_id' => 'a',
            'secret_key' => 'b',
            'sdk_app_id' => '1234',
        ]);

        self::assertSame('tencent', $gateway->name());
    }

    public function testOptionalVendorSdkGuardPassesWhenClassExists(): void
    {
        SdkGuard::requireClass(\stdClass::class, 'php', 'baidu');
        self::assertTrue(class_exists(\stdClass::class));
    }

    public function testOptionalVendorSdkGuardThrowsWhenPackageMissing(): void
    {
        $this->expectException(MissingSdkException::class);
        $this->expectExceptionMessage('vendor/baidu-sms');

        SdkGuard::requireClass('Vendor\\Baidu\\SmsClient', 'vendor/baidu-sms', 'baidu');
    }

    public function testFactoryAcceptsExtendedVendor(): void
    {
        $factory = new SmsFactory();
        $factory->extend('baidu', static fn (array $config, string $name): FakeSmsGateway => new FakeSmsGateway('baidu'));

        $gateway = $factory->make('baidu_cn', ['gateway' => 'baidu']);

        self::assertSame('baidu', $gateway->name());
    }
}
