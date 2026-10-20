<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Library\Sms\Exception\InvalidConfigException;
use Swoolefy\Library\Sms\Exception\InvalidPhoneException;
use Swoolefy\Library\Sms\Exception\InvalidProviderException;
use Swoolefy\Library\Sms\Exception\RateLimitExceededException;
use Swoolefy\Library\Sms\Gateway\AliyunGateway;
use Swoolefy\Library\Sms\Gateway\LogGateway;
use Swoolefy\Library\Sms\Gateway\TencentGateway;
use Swoolefy\Library\Sms\SmsManager;
use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\Support\ApiExceptionFactory;
use Swoolefy\Library\Sms\Testing\ArrayRateGuard;
use Swoolefy\Library\Sms\Testing\ArraySmsStore;
use Swoolefy\Library\Sms\ValueObject\PhoneList;
use Swoolefy\Library\Sms\ValueObject\SendResult;
use Swoolefy\Library\Sms\Verification\CodeGenerator;
use Swoolefy\Library\Sms\Verification\DurationRateGuard;
use Swoolefy\Library\Sms\Verification\RedisSmsStore;
use Swoolefy\Library\Sms\Verification\SmsVerifier;

/**
 * 对照审查问题的模拟验证：不连接厂商，断言请求字段、限流顺序、模板隔离和脱敏缓存。
 */
final class SmsFixTest extends TestCase
{
    public function testTencentPayloadUsesTemplateIdAndE164(): void
    {
        $gateway = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
            'timeout' => 3,
        ], new \stdClass());

        $payload = $gateway->buildRequestPayload(
            SmsMessage::make('13800008000', 'login_code', ['code' => '123456'])
        );

        self::assertSame(['+8613800008000'], $payload['PhoneNumberSet']);
        self::assertSame('100001', $payload['TemplateId']);
        self::assertArrayNotHasKey('TemplateCode', $payload);
        self::assertSame(['123456'], $payload['TemplateParamSet']);
        self::assertSame(3, $gateway->clientTimeoutSeconds());
    }

    public function testTencentPayloadNormalizesAllFourRegions(): void
    {
        $gateway = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
        ], new \stdClass());

        self::assertSame(
            ['+8613800008000'],
            $gateway->buildRequestPayload(SmsMessage::make('13800008000', 'login_code'))['PhoneNumberSet']
        );
        self::assertSame(
            ['+85261234567'],
            $gateway->buildRequestPayload(SmsMessage::make('+852 6123 4567', 'login_code'))['PhoneNumberSet']
        );
        self::assertSame(
            ['+85366123456'],
            $gateway->buildRequestPayload(SmsMessage::make('85366123456', 'login_code'))['PhoneNumberSet']
        );
        self::assertSame(
            ['+886912345678'],
            $gateway->buildRequestPayload(SmsMessage::make('0912345678', 'login_code'))['PhoneNumberSet']
        );
    }

    public function testTencentUsesDefaultRegionForBareEightDigitNumbers(): void
    {
        $hongKong = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
            'default_region' => 'HK',
        ], new \stdClass());
        self::assertSame(
            ['+85261234567'],
            $hongKong->buildRequestPayload(SmsMessage::make('61234567', 'login_code'))['PhoneNumberSet']
        );

        $macau = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
            'default_region' => 'MO',
        ], new \stdClass());
        self::assertSame(
            ['+85366123456'],
            $macau->buildRequestPayload(SmsMessage::make('66123456', 'login_code'))['PhoneNumberSet']
        );
    }

    public function testTencentRejectsBareEightDigitWithoutHkMoDefault(): void
    {
        $gateway = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
        ], new \stdClass());

        $this->expectException(InvalidPhoneException::class);
        $gateway->buildRequestPayload(SmsMessage::make('61234567', 'login_code'));
    }

    public function testTencentAcceptsDefaultRegionWithPlusPrefix(): void
    {
        $gateway = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
            'default_region' => '+86',
        ], new \stdClass());

        self::assertSame(
            ['+8613800008000'],
            $gateway->buildRequestPayload(SmsMessage::make('13800008000', 'login_code'))['PhoneNumberSet']
        );
    }

    public function testTencentRejectsMixedMainlandAndOverseasNumbers(): void
    {
        $gateway = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
        ], new \stdClass());

        $this->expectException(InvalidPhoneException::class);
        $gateway->buildRequestPayload(SmsMessage::make(
            ['13800008000', '+85261234567'],
            'login_code'
        ));
    }

    public function testTencentAllowsMultipleOverseasNumbers(): void
    {
        $gateway = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
        ], new \stdClass());

        $payload = $gateway->buildRequestPayload(SmsMessage::make(
            ['+85261234567', '0912345678', '+14155552671'],
            'login_code'
        ));

        self::assertSame(
            ['+85261234567', '+886912345678', '+14155552671'],
            $payload['PhoneNumberSet']
        );
    }

    public function testAliyunPayloadNormalizesAllFourRegions(): void
    {
        $gateway = new AliyunGateway([
            'sign_name' => '示例签名',
            'templates' => ['login_code' => 'SMS_100001'],
        ], new \stdClass());

        self::assertSame(
            '13800008000',
            $gateway->buildRequestPayload(SmsMessage::make('+8613800008000', 'login_code'))['phoneNumbers']
        );
        self::assertSame(
            '85261234567',
            $gateway->buildRequestPayload(SmsMessage::make('+85261234567', 'login_code'))['phoneNumbers']
        );
        self::assertSame(
            '85366123456',
            $gateway->buildRequestPayload(SmsMessage::make('85366123456', 'login_code'))['phoneNumbers']
        );
        self::assertSame(
            '886912345678',
            $gateway->buildRequestPayload(SmsMessage::make('0912345678', 'login_code'))['phoneNumbers']
        );
    }

    public function testAliyunAcceptsMixedMainlandAndOverseasNumbers(): void
    {
        $gateway = new AliyunGateway([
            'sign_name' => '示例签名',
            'templates' => ['login_code' => 'SMS_100001'],
        ], new \stdClass());

        self::assertSame(
            '13800008000,85261234567',
            $gateway->buildRequestPayload(SmsMessage::make(
                ['13800008000', '+85261234567'],
                'login_code'
            ))['phoneNumbers']
        );
    }

    public function testTencentRejectsMoreThan200Phones(): void
    {
        $gateway = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
        ], new \stdClass());

        $phones = [];
        for ($i = 0; $i < 201; $i++) {
            $phones[] = '138' . str_pad((string) $i, 8, '0', STR_PAD_LEFT);
        }

        $this->expectException(InvalidPhoneException::class);
        $gateway->buildRequestPayload(SmsMessage::make($phones, 'login_code', ['code' => '1']));
    }

    public function testAliyunPayloadMapsAliasAndTimeout(): void
    {
        $gateway = new AliyunGateway([
            'sign_name' => '示例签名',
            'templates' => ['login_code' => 'SMS_100001'],
            'timeout' => 8,
        ], new \stdClass());

        $payload = $gateway->buildRequestPayload(
            SmsMessage::make(['13800008000', '13900009000'], 'login_code', ['code' => '654321'])
        );

        self::assertSame('13800008000,13900009000', $payload['phoneNumbers']);
        self::assertSame('SMS_100001', $payload['templateCode']);
        self::assertSame('{"code":"654321"}', $payload['templateParam']);
        self::assertSame(
            ['connectTimeout' => 8000, 'readTimeout' => 8000],
            $gateway->clientTimeouts()
        );
    }

    public function testUnknownTemplateAliasIsRejected(): void
    {
        $gateway = new AliyunGateway([
            'sign_name' => '示例签名',
            'templates' => ['login_code' => 'SMS_100001'],
        ], new \stdClass());

        self::assertSame('SMS_999', $gateway->templateCode('SMS_999'));

        $this->expectException(InvalidProviderException::class);
        $gateway->templateCode('order_notice');
    }

    public function testTencentRequestIdComesFromResponseNotSerial(): void
    {
        $phones = PhoneList::make(['13800008000']);
        $result = SendResult::fromTencentBody([
            ['PhoneNumber' => '+8613800008000', 'Code' => 'Ok', 'Message' => 'SUCCESS', 'SerialNumber' => 'serial-1'],
        ], $phones, 'tencent', 'req-top');

        self::assertSame('req-top', $result->requestId);
        self::assertSame('serial-1', $result->bizId);
    }

    public function testSdkExceptionKeepsProviderCodeAndRequestId(): void
    {
        $exception = ApiExceptionFactory::fromSdk('tencent', new FakeSdkException('denied'));

        self::assertSame('AuthFailure.SignatureFailure', $exception->getProviderCode());
        self::assertSame('req-9', $exception->getRequestId());
        self::assertStringNotContainsString('secret', $exception->getMessage());
    }

    public function testCooldownRetryDoesNotConsumeRateQuota(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->verifier($store, $rate);

        $verifier->send('13800008000');
        self::assertSame(1, $rate->count('sms:rl:login_code:+8613800008000'));

        try {
            $verifier->send('13800008000');
            self::fail('cooldown should reject');
        } catch (RateLimitExceededException) {
        }

        self::assertSame(1, $rate->count('sms:rl:login_code:+8613800008000'));
    }

    public function testDifferentTemplatesDoNotOverwriteEachOther(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->verifier($store, $rate);

        $verifier->send('13800008000', 'login_code');
        $verifier->send('13800008000', 'register_code');

        self::assertNotNull($store->get('code:login_code:+8613800008000'));
        self::assertNotNull($store->get('code:register_code:+8613800008000'));
        self::assertTrue($verifier->verify('13800008000', '999999', 'login_code')->ok);
        self::assertNotNull($store->get('code:register_code:+8613800008000'));
    }

    public function testLogGatewayRedactsParamsAndCapsHistory(): void
    {
        $gateway = new LogGateway(['gateway' => 'log']);
        for ($i = 0; $i < 40; $i++) {
            $gateway->send(SmsMessage::make('13800008000', 'login_code', ['code' => (string) $i]));
        }

        self::assertCount(32, $gateway->sentMessages());
        self::assertSame(['code' => '***'], $gateway->lastMessage()?->params);
    }

    public function testDurationRateGuardUsesTheKeyOfEachCall(): void
    {
        $redis = new RecordingSmsRedis();
        $guard = new DurationRateGuard($redis);

        self::assertFalse($guard->tooMany('sms:rl:13800008000', 5, 60));
        self::assertFalse($guard->tooMany('sms:rl:13900009000', 5, 60));

        self::assertCount(2, $redis->keys);
        self::assertStringContainsString('13800008000', (string) $redis->keys[0]);
        self::assertStringContainsString('13900009000', (string) $redis->keys[1]);
        self::assertNotSame($redis->keys[0], $redis->keys[1]);
    }

    public function testTencentBlankMessageSignFallsBackToDefault(): void
    {
        $gateway = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
        ], new \stdClass());

        foreach (['', '   '] as $sign) {
            $payload = $gateway->buildRequestPayload(SmsMessage::make('13800008000', 'login_code', [], $sign));
            self::assertSame('示例签名', $payload['SignName']);
        }

        $payload = $gateway->buildRequestPayload(SmsMessage::make('13800008000', 'login_code', [], ' 其他签名 '));
        self::assertSame('其他签名', $payload['SignName']);
    }

    public function testAliyunBlankMessageSignFallsBackToDefault(): void
    {
        $gateway = new AliyunGateway([
            'sign_name' => '示例签名',
            'templates' => ['login_code' => 'SMS_100001'],
        ], new \stdClass());

        foreach (['', '   '] as $sign) {
            $payload = $gateway->buildRequestPayload(SmsMessage::make('13800008000', 'login_code', [], $sign));
            self::assertSame('示例签名', $payload['signName']);
        }

        $payload = $gateway->buildRequestPayload(SmsMessage::make('13800008000', 'login_code', [], ' 其他签名 '));
        self::assertSame('其他签名', $payload['signName']);
    }

    public function testRedisStoreRejectsNonPositiveTtl(): void
    {
        $store = new RedisSmsStore('cache');
        $calls = [
            'put' => static fn () => $store->put('k', 'v', 0),
            'put-negative' => static fn () => $store->put('k', 'v', -1),
            'add' => static fn () => $store->add('k', 'v', 0),
            'increment' => static fn () => $store->increment('k', 0),
            'verifyCode' => static fn () => $store->verifyCode('c', 'a', 't', 'cd', '1', 5, 0, 10),
        ];

        $rejected = 0;
        foreach ($calls as $label => $call) {
            try {
                $call();
                self::fail('ttl<=0 accepted: ' . $label);
            } catch (InvalidConfigException) {
                $rejected++;
            }
        }

        self::assertSame(count($calls), $rejected);
    }

    public function testDurationRateGuardRejectsNonPositiveLimits(): void
    {
        $guard = new DurationRateGuard('cache');
        $rejected = 0;

        foreach ([[0, 60], [5, 0]] as [$max, $window]) {
            try {
                $guard->tooMany('key', $max, $window);
                self::fail(sprintf('invalid limits accepted: max=%d window=%d', $max, $window));
            } catch (InvalidConfigException) {
                $rejected++;
            }
        }

        self::assertSame(2, $rejected);
    }

    public function testCodeGeneratorRejectsUnknownType(): void
    {
        $this->expectException(InvalidConfigException::class);
        new CodeGenerator(6, 'numeric');
    }

    public function testCodeGeneratorNormalizesTypeCaseAndWhitespace(): void
    {
        $generator = new CodeGenerator(6, ' ALNUM ', static fn (int $min, int $max): int => $max);

        self::assertSame('alnum', $generator->getType());
        self::assertSame('ZZZZZZ', $generator->generate());
    }

    /**
     * @param array<string, mixed> $verification
     */
    private function verifier(ArraySmsStore $store, ArrayRateGuard $rate, array $verification = []): SmsVerifier
    {
        $manager = new SmsManager([
            'default_gateway' => 'main',
            'gateways' => ['main' => ['gateway' => 'log']],
        ]);

        return new SmsVerifier(
            $manager,
            $store,
            $rate,
            array_merge([
                'ttl' => 300,
                'max_verify' => 5,
                'resend_interval' => 60,
                'rate_limit' => ['enabled' => true, 'key' => 'sms:rl:{template}:{phone}', 'max' => 5, 'period' => 3600],
            ], $verification),
            new CodeGenerator(6, 'number', static fn (int $min, int $max): int => $max),
        );
    }
}

final class FakeSdkException extends \RuntimeException
{
    public function getErrorCode(): string
    {
        return 'AuthFailure.SignatureFailure';
    }

    public function getRequestId(): string
    {
        return 'req-9';
    }
}

final class RecordingSmsRedis extends RedisConnection
{
    /** @var list<string|null> */
    public array $keys = [];

    public function eval(...$args)
    {
        $params = $args[1] ?? [];
        $this->keys[] = isset($params[0]) ? (string) $params[0] : null;

        return 0;
    }
}
