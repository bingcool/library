<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Library\Sms\Contracts\RateGuardInterface;
use Swoolefy\Library\Sms\Exception\InvalidConfigException;
use Swoolefy\Library\Sms\Exception\InvalidPhoneException;
use Swoolefy\Library\Sms\Exception\InvalidProviderException;
use Swoolefy\Library\Sms\Exception\RateLimitExceededException;
use Swoolefy\Library\Sms\Exception\SmsApiException;
use Swoolefy\Library\Sms\Exception\SmsStorageException;
use Swoolefy\Library\Sms\Gateway\LogGateway;
use Swoolefy\Library\Sms\Gateway\TencentGateway;
use Swoolefy\Library\Sms\SmsManager;
use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\Support\ApiExceptionFactory;
use Swoolefy\Library\Sms\Support\TemplateCodeResolver;
use Swoolefy\Library\Sms\Support\TemplateParamMapper;
use Swoolefy\Library\Sms\Testing\ArrayRateGuard;
use Swoolefy\Library\Sms\Testing\ArraySmsStore;
use Swoolefy\Library\Sms\Testing\FakeSmsGateway;
use Swoolefy\Library\Sms\ValueObject\SendResult;
use Swoolefy\Library\Sms\Verification\CodeGenerator;
use Swoolefy\Library\Sms\Verification\DurationRateGuard;
use Swoolefy\Library\Sms\Verification\RedisSmsStore;
use Swoolefy\Library\Sms\Verification\SmsVerifier;

/**
 * P2 审计项回归：存码顺序、异常边界、TTL 语义、日志/PII、模板与隐私。
 */
final class P2RegressionTest extends TestCase
{
    private const PHONE = '13800008000';

    private const KEY_PHONE = '+8613800008000';

    private const CODE = '999999';

    private function makeVerifier(
        ArraySmsStore $store,
        ArrayRateGuard $rate,
        ?FakeSmsGateway $gateway = null,
        array $verification = [],
    ): SmsVerifier {
        $manager = new SmsManager([
            'sms' => [
                'default_gateway' => 'main',
                'gateways' => ['main' => ['gateway' => 'fake']],
            ],
        ]);

        $gateway ??= new FakeSmsGateway('fake');
        $manager->extendDriver('fake', static fn (array $config, string $name): FakeSmsGateway => $gateway);

        $config = array_merge([
            'ttl' => 300,
            'max_verify' => 5,
            'resend_interval' => 60,
            'rate_limit' => ['enabled' => true, 'key' => 'sms:rl:{template}:{phone}', 'max' => 3, 'period' => 3600],
        ], $verification);

        return new SmsVerifier(
            $manager,
            $store,
            $rate,
            $config,
            new CodeGenerator(6, 'number', static fn (int $min, int $max): int => $max),
        );
    }

    /**
     * @return object{records: list<array{level: mixed, message: string, context: array<string, mixed>}>}
     */
    private function makeLogger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }

    public function testCodeIsStoredBeforeGatewaySendReturns(): void
    {
        $store = new ArraySmsStore();
        $fake = new FakeSmsGateway('fake');
        $observedCode = null;

        $fake->responder(function (SmsMessage $message) use ($store, &$observedCode): SendResult {
            $observedCode = $store->get('code:login_code:' . self::KEY_PHONE);

            return SendResult::success($message->phones->toArray(), 'fake');
        });

        $verifier = $this->makeVerifier($store, new ArrayRateGuard(), $fake);

        self::assertTrue($verifier->send(self::PHONE)->ok);
        self::assertSame(self::CODE, $observedCode);
        self::assertSame(self::CODE, $store->get('code:login_code:' . self::KEY_PHONE));
    }

    public function testFailedSendRollsBackCodeAndCooldown(): void
    {
        $store = new ArraySmsStore();
        $fake = new FakeSmsGateway('fake', [], SendResult::failure([], 'fake', 'isv.FAIL', 'vendor rejected'));
        $verifier = $this->makeVerifier($store, new ArrayRateGuard(), $fake);

        $result = $verifier->send(self::PHONE);

        self::assertFalse($result->ok);
        self::assertFalse($store->exists('code:login_code:' . self::KEY_PHONE));
        self::assertFalse($store->exists('trace:login_code:' . self::KEY_PHONE));
        self::assertFalse($store->exists('cooldown:login_code:' . self::KEY_PHONE));

        // 冷却已释放，失败后可以立即重试
        self::assertFalse($verifier->send(self::PHONE)->ok);
    }

    public function testInfrastructureFailureIsWrappedAsSmsException(): void
    {
        $store = new class extends ArraySmsStore {
            public function add(string $key, string $value, int $ttl): bool
            {
                throw new \RuntimeException('redis down');
            }
        };

        $verifier = $this->makeVerifier($store, new ArrayRateGuard());

        try {
            $verifier->send(self::PHONE);
            self::fail('storage failure should be wrapped');
        } catch (SmsStorageException $e) {
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertStringContainsString('redis down', $e->getMessage());
        }
    }

    public function testCleanupFailureDoesNotMaskOriginalException(): void
    {
        $store = new class extends ArraySmsStore {
            public function forget(string $key): void
            {
                throw new \RuntimeException('forget failed');
            }
        };

        $fake = new FakeSmsGateway('fake');
        $fake->responder(static function (): SendResult {
            throw new SmsApiException('gateway down');
        });

        $verifier = $this->makeVerifier($store, new ArrayRateGuard(), $fake);

        $this->expectException(SmsApiException::class);
        $this->expectExceptionMessage('gateway down');
        $verifier->send(self::PHONE);
    }

    public function testPostSendBookkeepingFailureDoesNotFailSendOrReleaseCooldown(): void
    {
        $store = new class extends ArraySmsStore {
            public function put(string $key, string $value, int $ttl): void
            {
                if (str_starts_with($key, 'trace:')) {
                    throw new \RuntimeException('trace write failed');
                }

                parent::put($key, $value, $ttl);
            }
        };

        $verifier = $this->makeVerifier($store, new ArrayRateGuard());

        $result = $verifier->send(self::PHONE);

        self::assertTrue($result->ok);
        // 短信已发出：码保留、冷却保留，记账失败不能改判为发送失败
        self::assertSame(self::CODE, $store->get('code:login_code:' . self::KEY_PHONE));
        self::assertTrue($store->exists('cooldown:login_code:' . self::KEY_PHONE));
        self::assertFalse($store->exists('trace:login_code:' . self::KEY_PHONE));
        self::assertTrue($verifier->verify(self::PHONE, self::CODE)->ok);
    }

    public function testVerifierRejectsConfigKeysWithSurroundingWhitespace(): void
    {
        $cases = [
            [' resend_interval' => 1],
            ['rate_limit' => [
                'enabled' => true,
                'key' => 'sms:rl:{template}:{phone}',
                'max' => 1,
                'period' => 60,
                ' period' => 30,
            ]],
        ];

        $rejected = 0;
        foreach ($cases as $config) {
            try {
                $this->makeVerifier(new ArraySmsStore(), new ArrayRateGuard(), null, $config);
                self::fail('config key with whitespace should be rejected');
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString('whitespace', $e->getMessage());
                $rejected++;
            }
        }

        self::assertSame(2, $rejected);
    }

    public function testCooldownExceptionCarriesRemainingTtl(): void
    {
        $store = new ArraySmsStore();
        $verifier = $this->makeVerifier($store, new ArrayRateGuard());

        $verifier->send(self::PHONE);
        $store->advance(20);

        try {
            $verifier->send(self::PHONE);
            self::fail('cooldown should reject');
        } catch (RateLimitExceededException $e) {
            self::assertSame(RateLimitExceededException::REASON_COOLDOWN, $e->reason());
            self::assertSame(40, $e->retryAfter());
            self::assertStringContainsString('40 秒', $e->getMessage());
        }
    }

    public function testWindowExceptionCarriesRetryAfter(): void
    {
        $verifier = $this->makeVerifier(new ArraySmsStore(), new ArrayRateGuard(true, 37), null, [
            'resend_interval' => 0,
            'rate_limit' => ['enabled' => true, 'key' => 'sms:rl:{template}:{phone}', 'max' => 1, 'period' => 120],
        ]);

        try {
            $verifier->send(self::PHONE);
            self::fail('window should reject');
        } catch (RateLimitExceededException $e) {
            self::assertSame(RateLimitExceededException::REASON_WINDOW, $e->reason());
            // 命中窗口时返回最早一次消费滑出窗口的真实剩余秒数，而不是完整周期
            self::assertSame(37, $e->retryAfter());
            self::assertStringContainsString('37 秒', $e->getMessage());
        }
    }

    public function testWindowRetryAfterFallsBackToPeriodWhenRemainingLookupFails(): void
    {
        $guard = new class implements RateGuardInterface {
            public function tooMany(string $key, int $max, int $window): bool
            {
                return true;
            }

            public function remaining(string $key, int $window): int
            {
                throw new \RuntimeException('redis down');
            }
        };

        $manager = new SmsManager([
            'sms' => [
                'default_gateway' => 'main',
                'gateways' => ['main' => ['gateway' => 'fake']],
            ],
        ]);
        $manager->extendDriver(
            'fake',
            static fn (array $config, string $name): FakeSmsGateway => new FakeSmsGateway('fake')
        );

        $verifier = new SmsVerifier($manager, new ArraySmsStore(), $guard, [
            'resend_interval' => 0,
            'rate_limit' => ['enabled' => true, 'key' => 'sms:rl:{template}:{phone}', 'max' => 1, 'period' => 120],
        ]);

        try {
            $verifier->send(self::PHONE);
            self::fail('window should reject');
        } catch (RateLimitExceededException $e) {
            self::assertSame(RateLimitExceededException::REASON_WINDOW, $e->reason());
            self::assertSame(120, $e->retryAfter());
        }
    }

    public function testDurationRateGuardRemainingUsesSharedLimiter(): void
    {
        $redis = new P2RedisStub();
        $redis->evalResult = 42;
        $guard = new DurationRateGuard($redis, 'app:');

        self::assertSame(42, $guard->remaining('sms:rl:login_code:+8613800008000', 120));
        self::assertCount(1, $redis->calls);
        self::assertSame('eval', $redis->calls[0][0]);
        self::assertStringContainsString("redis.call('ZRANGE'", $redis->calls[0][1][0]);
        self::assertStringContainsString("redis.call('ZREMRANGEBYSCORE'", $redis->calls[0][1][0]);
        self::assertSame('_rate_limit:app:sms:rl:login_code:+8613800008000', (string) $redis->keys[0]);
        self::assertSame(120, $redis->calls[0][1][1][1]);
    }

    public function testArrayStoreTtlAdvancesAndIncrementDoesNotExtendTtl(): void
    {
        $store = new ArraySmsStore();
        $store->put('k', 'v', 10);
        self::assertSame(10, $store->ttl('k'));

        $store->advance(4);
        self::assertSame(6, $store->ttl('k'));

        $store->increment('n', 10);
        $store->advance(2);
        $store->increment('n', 10);
        self::assertSame(8, $store->ttl('n'));
        self::assertSame('2', $store->get('n'));

        $store->advance(6);
        self::assertNull($store->get('k'));
        self::assertSame(-2, $store->ttl('k'));
    }

    public function testArrayRateGuardRejectsInvalidWindow(): void
    {
        $this->expectException(InvalidConfigException::class);
        (new ArrayRateGuard())->tooMany('k', 0, 60);
    }

    public function testDurationRateGuardAppliesConfiguredPrefix(): void
    {
        $redis = new P2RedisStub();
        $guard = new DurationRateGuard($redis, 'app:');

        self::assertFalse($guard->tooMany('sms:rl:login_code:+8613800008000', 5, 60));
        self::assertCount(1, $redis->keys);
        self::assertSame('_rate_limit:app:sms:rl:login_code:+8613800008000', (string) $redis->keys[0]);
    }

    public function testDurationRateGuardDefaultPrefixDoesNotDuplicateNamespace(): void
    {
        $redis = new P2RedisStub();
        $guard = new DurationRateGuard($redis);

        self::assertFalse($guard->tooMany('sms:rl:login_code:+8613800008000', 5, 60));
        self::assertCount(1, $redis->keys);
        self::assertSame('_rate_limit:sms:rl:login_code:+8613800008000', (string) $redis->keys[0]);
        self::assertStringNotContainsString('sms:rg:', (string) $redis->keys[0]);
    }

    public function testApiExceptionFactoryFallsBackToThrowableCode(): void
    {
        $exception = ApiExceptionFactory::fromSdk('aliyun', new \Exception('boom', 123));

        self::assertSame('123', $exception->getProviderCode());
    }

    public function testTemplateResolverRejectsAliasWhenMappingIsEmpty(): void
    {
        try {
            TemplateCodeResolver::resolve([], 'login_code');
            self::fail('unconfigured alias should be rejected');
        } catch (InvalidProviderException $e) {
            self::assertStringContainsString('login_code', $e->getMessage());
        }

        self::assertSame('SMS_999', TemplateCodeResolver::resolve([], 'SMS_999'));
        self::assertSame('100001', TemplateCodeResolver::resolve(['login_code' => '100001'], 'login_code'));
    }

    public function testFakeGatewayUsesProductionTemplateRules(): void
    {
        $fake = new FakeSmsGateway('fake');

        $this->expectException(InvalidProviderException::class);
        $fake->templateCode('login_code');
    }

    public function testRedisStoreIncrementIsSingleAtomicEval(): void
    {
        $redis = new P2RedisStub();
        $redis->evalResult = 5;
        $store = new RedisSmsStore($redis);

        self::assertSame(5, $store->increment('attempts:login_code:+8613800008000', 60));
        self::assertCount(1, $redis->calls);
        self::assertSame('eval', $redis->calls[0][0]);
        self::assertStringContainsString("redis.call('INCR'", $redis->calls[0][1][0]);
        self::assertStringContainsString("redis.call('TTL'", $redis->calls[0][1][0]);
    }

    public function testRedisStoreAddHandlesPhpredisObjectsWithoutStringCast(): void
    {
        $redis = new P2RedisStub();
        $store = new RedisSmsStore($redis);

        $redis->setResult = true;
        self::assertTrue($store->add('k', 'v', 30));

        $redis->setResult = false;
        self::assertFalse($store->add('k', 'v', 30));

        $redis->setResult = 'OK';
        self::assertTrue($store->add('k', 'v', 30));

        if (class_exists(\Redis::class)) {
            $redis->setResult = new \Redis();
            // 以前这里会对 \Redis 做 (string) 转换并致命错误
            self::assertTrue($store->add('k', 'v', 30));
        }
    }

    public function testLogGatewayKeepsMaskedHistoryAndTotalCount(): void
    {
        $gateway = new LogGateway(['gateway' => 'log']);

        for ($i = 0; $i < 40; $i++) {
            $gateway->send(SmsMessage::make(self::PHONE, 'login_code', ['code' => (string) $i]));
        }

        self::assertSame(40, $gateway->sendCount());
        self::assertCount(32, $gateway->sentMessages());
        self::assertSame(['138****8000'], $gateway->lastMessage()?->phones);
        self::assertSame(['code' => '***'], $gateway->lastMessage()?->params);
        self::assertStringNotContainsString(self::PHONE, json_encode($gateway->sentMessages(), JSON_UNESCAPED_UNICODE));
    }

    public function testManagerLogsExactlyOnceThroughLogGateway(): void
    {
        $logger = $this->makeLogger();
        $manager = new SmsManager([
            'sms' => [
                'default_gateway' => 'main',
                'gateways' => ['main' => ['gateway' => 'log']],
            ],
        ], $logger);

        $manager->send(SmsMessage::make(self::PHONE, 'login_code'));

        self::assertCount(1, $logger->records);
        self::assertSame('sms send', $logger->records[0]['message']);
        self::assertSame(['138****8000'], $logger->records[0]['context']['phones']);
    }

    public function testLogGatewayCanExplicitlyKeepPhones(): void
    {
        $gateway = new LogGateway(['gateway' => 'log', 'keep_phones' => true]);
        $gateway->send(SmsMessage::make(self::PHONE, 'login_code'));

        self::assertSame([self::PHONE], $gateway->lastMessage()?->phones);
    }

    public function testManagerLogsGatewayExceptionsWithChannelAndRequestId(): void
    {
        $logger = $this->makeLogger();
        $manager = new SmsManager([
            'sms' => [
                'default_gateway' => 'main',
                'gateways' => ['main' => ['gateway' => 'fake']],
            ],
        ], $logger);

        $fake = new FakeSmsGateway('fake');
        $fake->responder(static function (): SendResult {
            throw new SmsApiException('vendor down', 0, null, 'isv.SMS_FAIL', 'req-123');
        });
        $manager->extendDriver('fake', static fn (array $config, string $name): FakeSmsGateway => $fake);

        try {
            $manager->send(SmsMessage::make(
                ['13800008000', '13900009000', '13700007000', '13600006000', '13500005000', '13400004000'],
                'login_code'
            ));
            self::fail('gateway exception should propagate');
        } catch (SmsApiException) {
        }

        $records = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => $record['message'] === 'sms send failed'
        ));
        self::assertCount(1, $records);
        $context = $records[0]['context'];
        self::assertSame('main', $context['channel']);
        self::assertSame('fake', $context['gateway']);
        self::assertSame('isv.SMS_FAIL', $context['provider_code']);
        self::assertSame('req-123', $context['request_id']);
        self::assertSame(6, $context['count']);
        self::assertCount(5, $context['phones']);
        self::assertTrue($context['phones_truncated']);
        self::assertArrayHasKey('duration_ms', $context);
        // 厂商异常原文可能带请求细节，不落日志
        self::assertArrayNotHasKey('message', $context);
    }

    public function testManagerDoesNotLogRawPhoneFromInvalidPhoneException(): void
    {
        $logger = $this->makeLogger();
        $manager = new SmsManager([
            'sms' => [
                'default_gateway' => 'main',
                'gateways' => ['main' => ['gateway' => 'fake']],
            ],
        ], $logger);

        $fake = new FakeSmsGateway('fake');
        $fake->responder(static function (): SendResult {
            throw new InvalidPhoneException('Invalid phone number: 13900009000');
        });
        $manager->extendDriver('fake', static fn (array $config, string $name): FakeSmsGateway => $fake);

        try {
            $manager->send(SmsMessage::make('13800008000', 'login_code'));
            self::fail('invalid phone should propagate');
        } catch (InvalidPhoneException) {
        }

        $records = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => $record['message'] === 'sms send failed'
        ));
        self::assertCount(1, $records);
        self::assertArrayNotHasKey('message', $records[0]['context']);
        self::assertStringNotContainsString('13900009000', json_encode($records[0]['context'], JSON_UNESCAPED_UNICODE));
    }

    public function testVerifierHashesPhoneAndCodeInStorage(): void
    {
        $store = new ArraySmsStore();
        $fake = new FakeSmsGateway('fake');
        $verifier = $this->makeVerifier($store, new ArrayRateGuard(), $fake, [
            'key_prefix' => 'app1:test:',
            'hash_key' => str_repeat('k', 32),
            'allowed_templates' => ['login_code'],
        ]);

        self::assertTrue($verifier->send(self::PHONE)->ok);
        $code = (string) $fake->lastMessage()?->params['code'];

        $dump = $store->dump();
        self::assertNotEmpty($dump);
        $keys = implode(',', array_keys($dump));
        self::assertStringContainsString('app1:test:code:login_code:', $keys);
        self::assertStringNotContainsString(self::KEY_PHONE, $keys);
        self::assertStringNotContainsString(self::PHONE, $keys);

        $storedCode = $store->get('app1:test:code:login_code:' . hash_hmac('sha256', self::KEY_PHONE, str_repeat('k', 32) . '|phone'));
        self::assertNotSame($code, $storedCode);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $storedCode);

        self::assertTrue($verifier->verify(self::PHONE, $code)->ok);
    }

    public function testVerifierRejectsUnknownOrUnsafeTemplate(): void
    {
        $verifier = $this->makeVerifier(new ArraySmsStore(), new ArrayRateGuard(), null, [
            'allowed_templates' => ['login_code'],
        ]);

        $rejected = 0;
        foreach (['register_code', 'login:code'] as $template) {
            try {
                $verifier->send(self::PHONE, $template);
                self::fail('template should be rejected: ' . $template);
            } catch (InvalidConfigException) {
                $rejected++;
            }
        }

        self::assertSame(2, $rejected);
    }

    public function testTencentParamOrderMapsPositionalValues(): void
    {
        self::assertSame(
            ['123456', '张三'],
            TemplateParamMapper::toTencent(['name' => '张三', 'code' => '123456'], ['code', 'name'])
        );

        $gateway = new TencentGateway([
            'sign_name' => '示例签名',
            'sdk_app_id' => '1400000000',
            'templates' => ['login_code' => '100001'],
            'param_order' => ['code', 'name'],
        ], new \stdClass());

        $payload = $gateway->buildRequestPayload(
            SmsMessage::make(self::PHONE, 'login_code', ['name' => '张三', 'code' => '123456'])
        );
        self::assertSame(['123456', '张三'], $payload['TemplateParamSet']);
    }

    public function testTencentParamOrderRejectsMismatchedParams(): void
    {
        $this->expectException(InvalidProviderException::class);
        TemplateParamMapper::toTencent(['name' => '张三'], ['code', 'name']);
    }
}

/**
 * RedisConnection 桩：只记录调用并按预置值返回，不发起真实连接。
 */
final class P2RedisStub extends RedisConnection
{
    /** @var list<string|null> */
    public array $keys = [];

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public mixed $evalResult = 0;

    public mixed $setResult = true;

    public function eval(...$args)
    {
        $script = (string) ($args[0] ?? '');
        $params = $args[1] ?? [];
        $this->calls[] = ['eval', [$script, is_array($params) ? $params : []]];
        $this->keys[] = isset($params[0]) ? (string) $params[0] : null;

        return $this->evalResult;
    }

    public function set(...$args)
    {
        $this->calls[] = ['set', $args];

        return $this->setResult;
    }
}
