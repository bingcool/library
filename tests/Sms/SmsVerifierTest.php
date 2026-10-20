<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel;
use Swoolefy\Library\Sms\Exception\InvalidConfigException;
use Swoolefy\Library\Sms\Exception\InvalidPhoneException;
use Swoolefy\Library\Sms\Exception\RateLimitExceededException;
use Swoolefy\Library\Sms\SmsManager;
use Swoolefy\Library\Sms\Testing\ArrayRateGuard;
use Swoolefy\Library\Sms\Testing\ArraySmsStore;
use Swoolefy\Library\Sms\ValueObject\VerifyResult;
use Swoolefy\Library\Sms\Verification\CodeGenerator;
use Swoolefy\Library\Sms\Verification\SmsVerifier;

/**
 * 验证码服务编排：发送 → 存储 / 冷却 / 限流，校验 → 命中 / 错误累计 / 防暴力失效。
 *
 * 全部依赖内存替身（ArraySmsStore / ArrayRateGuard）与 log 网关，脱机确定性运行。
 */
final class SmsVerifierTest extends TestCase
{
    private const PHONE = '13800008000';

    /** 缓存键统一使用的 canonical 号码 */
    private const KEY_PHONE = '+8613800008000';

    private const CODE = '999999';

    /**
     * @param array<string, mixed> $gatewayConfig log 网关配置（可 simulate=failure）
     * @param array<string, mixed> $verification 覆盖默认验证码策略
     */
    private function makeVerifier(
        ArraySmsStore $store,
        ArrayRateGuard $rate,
        array $gatewayConfig = ['gateway' => 'log'],
        array $verification = [],
    ): SmsVerifier {
        $manager = new SmsManager([
            'sms' => [
                'default_gateway' => 'main',
                'gateways' => ['main' => $gatewayConfig],
            ],
        ]);

        $config = array_merge([
            'ttl' => 300,
            'max_verify' => 5,
            'resend_interval' => 60,
            'rate_limit' => ['enabled' => true, 'key' => 'sms:rl:{template}:{phone}', 'max' => 3, 'period' => 3600],
        ], $verification);

        return new SmsVerifier($manager, $store, $rate, $config, new CodeGenerator(6, 'number', static fn (int $min, int $max): int => $max));
    }

    public function testSendStoresCodeAndCooldownOnSuccess(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        $result = $verifier->send(self::PHONE);

        self::assertTrue($result->ok);
        $code = $store->get('code:login_code:' . self::KEY_PHONE);
        self::assertSame(self::CODE, $code);
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertSame(300, $store->lastTtl('code:login_code:' . self::KEY_PHONE));
        self::assertTrue($store->exists('cooldown:login_code:' . self::KEY_PHONE));
    }

    public function testSendDoesNotStoreWhenGatewayFails(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate, ['gateway' => 'log', 'simulate' => 'failure']);

        $result = $verifier->send(self::PHONE);

        self::assertFalse($result->ok);
        self::assertNull($store->get('code:login_code:' . self::KEY_PHONE));
        self::assertFalse($store->exists('cooldown:login_code:' . self::KEY_PHONE));
    }

    public function testSecondSendLosesAtomicCooldownClaim(): void
    {
        // exists() 恒为 false，用来证明发送不再靠「先查再写」。占位失败的第二次请求不发短信、不扣配额。
        $store = new ExistsAlwaysFalseStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        self::assertTrue($verifier->send(self::PHONE)->ok);
        self::assertSame(1, $rate->count('sms:rl:login_code:' . self::KEY_PHONE));

        try {
            $verifier->send(self::PHONE);
            self::fail('second send should lose the cooldown claim');
        } catch (RateLimitExceededException) {
        }

        self::assertSame(1, $rate->count('sms:rl:login_code:' . self::KEY_PHONE));
        self::assertSame(self::CODE, $store->get('code:login_code:' . self::KEY_PHONE));
    }

    public function testResendWithinCooldownRejected(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        $verifier->send(self::PHONE);

        $this->expectException(RateLimitExceededException::class);
        $verifier->send(self::PHONE);
    }

    public function testWindowRateLimitRejected(): void
    {
        $store = new ArraySmsStore();
        // 强制超限：任何一次 tooMany 都返回 true
        $rate = new ArrayRateGuard(true);
        $verifier = $this->makeVerifier($store, $rate);

        $this->expectException(RateLimitExceededException::class);
        $verifier->send(self::PHONE);
    }

    public function testVerifyCorrectCodeDestroysState(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        $verifier->send(self::PHONE);
        $code = (string) $store->get('code:login_code:' . self::KEY_PHONE);

        $result = $verifier->verify(self::PHONE, $code);
        self::assertTrue($result->ok);
        self::assertSame(VerifyResult::STATUS_OK, $result->status);
        // 成功后 code / 冷却 / 计数 / 发送痕迹全部清理，再校验视为没发过
        self::assertNull($store->get('code:login_code:' . self::KEY_PHONE));
        self::assertFalse($store->exists('cooldown:login_code:' . self::KEY_PHONE));
        self::assertFalse($store->exists('attempts:login_code:' . self::KEY_PHONE));
        self::assertFalse($store->exists('trace:login_code:' . self::KEY_PHONE));
        self::assertSame(VerifyResult::STATUS_ABSENT, $verifier->verify(self::PHONE, $code)->status);
    }

    public function testVerifyWrongCodeFailsAndCountsAttempts(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        $verifier->send(self::PHONE);

        $result = $verifier->verify(self::PHONE, '000000');
        self::assertFalse($result->ok);
        self::assertSame(VerifyResult::STATUS_MISMATCH, $result->status);
        self::assertSame(4, $result->remaining);
        self::assertSame('验证码错误，还可尝试 4 次', $result->message);
        self::assertSame('1', $store->get('attempts:login_code:' . self::KEY_PHONE));
        // 未达上限，当前码仍可用
        self::assertNotNull($store->get('code:login_code:' . self::KEY_PHONE));
    }

    public function testVerifyLocksOutAfterMaxAttempts(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate, ['gateway' => 'log'], ['max_verify' => 2]);

        $verifier->send(self::PHONE);

        $first = $verifier->verify(self::PHONE, '111111');
        self::assertSame(VerifyResult::STATUS_MISMATCH, $first->status);
        self::assertSame(1, $first->remaining);

        $locked = $verifier->verify(self::PHONE, '222222');
        self::assertSame(VerifyResult::STATUS_LOCKED, $locked->status);
        self::assertSame(0, $locked->remaining);
        self::assertSame('验证码已失效，请重新获取', $locked->message);
        self::assertNull($store->get('code:login_code:' . self::KEY_PHONE));
        // 即便用正确码也不再通过，仍是已作废
        self::assertSame(VerifyResult::STATUS_LOCKED, $verifier->verify(self::PHONE, self::CODE)->status);
    }

    public function testCooldownAndRateLimitAreScopedByTemplate(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        $verifier->send(self::PHONE, 'login_code');
        $register = $verifier->send(self::PHONE, 'register_code');

        self::assertTrue($register->ok);
        self::assertTrue($store->exists('cooldown:login_code:' . self::KEY_PHONE));
        self::assertTrue($store->exists('cooldown:register_code:' . self::KEY_PHONE));
        self::assertSame(1, $rate->count('sms:rl:login_code:' . self::KEY_PHONE));
        self::assertSame(1, $rate->count('sms:rl:register_code:' . self::KEY_PHONE));

        $this->expectException(RateLimitExceededException::class);
        $verifier->send(self::PHONE, 'login_code');
    }

    public function testVerifyClearsOnlyThatTemplateCooldown(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate, ['gateway' => 'log'], ['resend_interval' => 60]);

        $verifier->send(self::PHONE, 'login_code');
        $verifier->send(self::PHONE, 'register_code');
        $code = (string) $store->get('code:login_code:' . self::KEY_PHONE);

        self::assertTrue($verifier->verify(self::PHONE, $code, 'login_code')->ok);
        self::assertFalse($store->exists('cooldown:login_code:' . self::KEY_PHONE));
        self::assertTrue($store->exists('cooldown:register_code:' . self::KEY_PHONE));
    }

    public function testVerifyUnknownPhoneIsAbsent(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        $result = $verifier->verify('13900009000', '123456');

        self::assertSame(VerifyResult::STATUS_ABSENT, $result->status);
        self::assertSame('请先获取验证码', $result->message);
        self::assertNull($result->remaining);
    }

    public function testVerifyAfterCodeRemovedIsExpired(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        $verifier->send(self::PHONE);
        $store->forget('code:login_code:' . self::KEY_PHONE);

        $result = $verifier->verify(self::PHONE, self::CODE);

        self::assertSame(VerifyResult::STATUS_EXPIRED, $result->status);
        self::assertSame('验证码已过期，请重新获取', $result->message);
    }

    public function testSendAndVerifyUseCanonicalPhoneKeys(): void
    {
        $spellings = [
            '+8613800008000',
            '13800008000',
            '8613800008000',
            '008613800008000',
            '+86 138-0000-8000',
        ];

        foreach ($spellings as $spelling) {
            $store = new ArraySmsStore();
            $rate = new ArrayRateGuard();
            $verifier = $this->makeVerifier($store, $rate);

            self::assertTrue($verifier->send('+86 138-0000-8000')->ok);

            $code = $store->get('code:login_code:' . self::KEY_PHONE);
            self::assertNotNull($code);
            self::assertSame(1, $rate->count('sms:rl:login_code:' . self::KEY_PHONE));

            try {
                $verifier->send($spelling);
                self::fail('不同写法的同一号码应共用冷却键: ' . $spelling);
            } catch (RateLimitExceededException) {
            }

            self::assertTrue($verifier->verify($spelling, (string) $code)->ok);
        }
    }

    public function testHongKongSpellingsShareCanonicalKeys(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate, ['gateway' => 'log'], ['default_region' => 'HK']);

        self::assertTrue($verifier->send('+852 6123 4567')->ok);
        $code = $store->get('code:login_code:+85261234567');
        self::assertNotNull($code);
        self::assertSame(1, $rate->count('sms:rl:login_code:+85261234567'));

        try {
            $verifier->send('0085261234567');
            self::fail('香港号码不同写法应共用冷却键');
        } catch (RateLimitExceededException) {
        }

        self::assertTrue($verifier->verify('85261234567', (string) $code)->ok);
    }

    public function testMacauBareEightDigitUsesConfiguredRegionForKey(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate, ['gateway' => 'log'], ['default_region' => 'MO']);

        self::assertTrue($verifier->send('66123456')->ok);
        $code = $store->get('code:login_code:+85366123456');
        self::assertNotNull($code);
        self::assertTrue($verifier->verify('+853 6612 3456', (string) $code)->ok);
    }

    public function testTaiwanLocalAndInternationalSpellingsShareCanonicalKeys(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        self::assertTrue($verifier->send('0912345678')->ok);
        $code = $store->get('code:login_code:+886912345678');
        self::assertNotNull($code);
        self::assertSame(1, $rate->count('sms:rl:login_code:+886912345678'));

        try {
            $verifier->send('+886 912 345 678');
            self::fail('台湾号码不同写法应共用冷却键');
        } catch (RateLimitExceededException) {
        }

        self::assertTrue($verifier->verify('886912345678', (string) $code)->ok);
    }

    public function testBareEightDigitWithoutHkMoRegionIsRejectedBeforeSend(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        try {
            $verifier->send('61234567');
            self::fail('默认地区为 CN 时裸 8 位无法判断归属');
        } catch (InvalidPhoneException) {
        }

        self::assertSame([], $store->dump());
        self::assertSame(0, $rate->count('sms:rl:login_code:+85261234567'));
    }

    public function testVerifyRejectsInvalidPhoneBeforeStorageAccess(): void
    {
        $store = new ArraySmsStore();
        $verifier = $this->makeVerifier($store, new ArrayRateGuard());

        foreach (['', 'not-a-phone', '12345'] as $phone) {
            try {
                $verifier->verify($phone, '123456');
                self::fail('非法号码应抛异常: ' . $phone);
            } catch (InvalidPhoneException) {
            }
        }

        self::assertSame([], $store->dump());
    }

    public function testConcurrentCorrectVerifyConsumesCodeOnlyOnce(): void
    {
        $store = new ArraySmsStore();
        $verifier = $this->makeVerifier($store, new ArrayRateGuard());
        $verifier->send(self::PHONE);
        $code = (string) $store->get('code:login_code:' . self::KEY_PHONE);

        $statuses = [];
        $this->runInCoroutine(function () use ($verifier, $code, &$statuses): void {
            $channel = new Channel(2);
            for ($i = 0; $i < 2; $i++) {
                go(function () use ($verifier, $code, $channel): void {
                    $channel->push($verifier->verify(self::PHONE, $code)->status);
                });
            }
            for ($i = 0; $i < 2; $i++) {
                $statuses[] = $channel->pop(1.0);
            }
        });

        sort($statuses);
        self::assertSame([VerifyResult::STATUS_ABSENT, VerifyResult::STATUS_OK], $statuses);
        self::assertFalse($store->exists('code:login_code:' . self::KEY_PHONE));
    }

    public function testConcurrentWrongGuessesStopAtMaxVerify(): void
    {
        $store = new ArraySmsStore();
        $verifier = $this->makeVerifier($store, new ArrayRateGuard(), ['gateway' => 'log'], ['max_verify' => 5]);
        $verifier->send(self::PHONE);

        $statuses = [];
        $this->runInCoroutine(function () use ($verifier, &$statuses): void {
            $channel = new Channel(20);
            for ($i = 0; $i < 20; $i++) {
                go(function () use ($verifier, $channel): void {
                    $channel->push($verifier->verify(self::PHONE, '000000')->status);
                });
            }
            for ($i = 0; $i < 20; $i++) {
                $statuses[] = $channel->pop(1.0);
            }
        });

        self::assertCount(4, array_keys($statuses, VerifyResult::STATUS_MISMATCH, true));
        self::assertCount(16, array_keys($statuses, VerifyResult::STATUS_LOCKED, true));
        self::assertSame('5', $store->get('attempts:login_code:' . self::KEY_PHONE));
        self::assertFalse($store->exists('code:login_code:' . self::KEY_PHONE));
        self::assertSame('locked', $store->get('trace:login_code:' . self::KEY_PHONE));
    }

    public function testRejectsInvalidNumericConfig(): void
    {
        $cases = [
            'ttl' => ['ttl' => 0],
            'ttl-negative' => ['ttl' => -1],
            'max_verify' => ['max_verify' => 0],
            'resend_interval' => ['resend_interval' => -1],
        ];

        foreach ($cases as $label => $override) {
            try {
                $this->makeVerifier(new ArraySmsStore(), new ArrayRateGuard(), ['gateway' => 'log'], $override);
                self::fail('invalid verification config accepted: ' . $label);
            } catch (InvalidConfigException $e) {
                self::assertNotSame('', $e->getMessage(), $label);
            }
        }
    }

    public function testRejectsInvalidRateLimitConfiguration(): void
    {
        $cases = [
            'max=0' => ['enabled' => true, 'key' => 'sms:rl:{template}:{phone}', 'max' => 0, 'period' => 60],
            'period=0' => ['enabled' => true, 'key' => 'sms:rl:{template}:{phone}', 'max' => 1, 'period' => 0],
            'key-without-phone' => ['enabled' => true, 'key' => 'sms:rl:{template}', 'max' => 1, 'period' => 60],
        ];

        foreach ($cases as $label => $rate) {
            try {
                $this->makeVerifier(new ArraySmsStore(), new ArrayRateGuard(), ['gateway' => 'log'], ['rate_limit' => $rate]);
                self::fail('invalid rate config accepted: ' . $label);
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString('rate_limit', $e->getMessage(), $label);
            }
        }
    }

    public function testRejectsNonBooleanRateEnabled(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->makeVerifier(new ArraySmsStore(), new ArrayRateGuard(), ['gateway' => 'log'], [
            'rate_limit' => [
                'enabled' => 'sometimes',
                'key' => 'sms:rl:{template}:{phone}',
                'max' => 5,
                'period' => 3600,
            ],
        ]);
    }

    public function testStringFalseDisablesRateLimit(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate, ['gateway' => 'log'], [
            'rate_limit' => [
                'enabled' => 'false',
                'key' => 'sms:rl:{template}:{phone}',
                'max' => 0,
                'period' => 0,
            ],
        ]);

        self::assertTrue($verifier->send(self::PHONE)->ok);
        self::assertSame(0, $rate->count('sms:rl:login_code:' . self::KEY_PHONE));
    }

    public function testStringTrueKeepsRateLimitEnabled(): void
    {
        $verifier = $this->makeVerifier(new ArraySmsStore(), new ArrayRateGuard(true), ['gateway' => 'log'], [
            'rate_limit' => [
                'enabled' => 'true',
                'key' => 'sms:rl:{template}:{phone}',
                'max' => 5,
                'period' => 3600,
            ],
        ]);

        $this->expectException(RateLimitExceededException::class);
        $verifier->send(self::PHONE);
    }

    public function testRejectsBlankCodeParamKey(): void
    {
        $manager = new SmsManager([
            'default_gateway' => 'main',
            'gateways' => ['main' => ['gateway' => 'log']],
        ]);

        $this->expectException(InvalidConfigException::class);
        new SmsVerifier(
            $manager,
            new ArraySmsStore(),
            new ArrayRateGuard(),
            ['ttl' => 300, 'max_verify' => 5, 'resend_interval' => 60],
            null,
            null,
            '  ',
        );
    }

    /**
     * 协程相关用例需要 ext-swoole；与 Uuid 测试保持同一跳过策略。
     */
    private function runInCoroutine(callable $fn): void
    {
        if (!class_exists(Channel::class) || !class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('ext-swoole is required for concurrency tests');
        }
        if (\Swoole\Coroutine::getCid() > 0) {
            $fn();

            return;
        }

        \Swoole\Coroutine\run($fn);
    }
}

/**
 * exists() 永远返回 false，用来卡住「先查再写」的旧实现。add() 仍按键是否已占用返回。
 */
final class ExistsAlwaysFalseStore extends ArraySmsStore
{
    public function exists(string $key): bool
    {
        return false;
    }
}
