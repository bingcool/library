<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
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
        $code = $store->get('code:login_code:' . self::PHONE);
        self::assertSame(self::CODE, $code);
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertSame(300, $store->lastTtl('code:login_code:' . self::PHONE));
        self::assertTrue($store->exists('cooldown:login_code:' . self::PHONE));
    }

    public function testSendDoesNotStoreWhenGatewayFails(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate, ['gateway' => 'log', 'simulate' => 'failure']);

        $result = $verifier->send(self::PHONE);

        self::assertFalse($result->ok);
        self::assertNull($store->get('code:login_code:' . self::PHONE));
        self::assertFalse($store->exists('cooldown:login_code:' . self::PHONE));
    }

    public function testSecondSendLosesAtomicCooldownClaim(): void
    {
        // exists() 恒为 false，用来证明发送不再靠「先查再写」。占位失败的第二次请求不发短信、不扣配额。
        $store = new ExistsAlwaysFalseStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        self::assertTrue($verifier->send(self::PHONE)->ok);
        self::assertSame(1, $rate->count('sms:rl:login_code:' . self::PHONE));

        try {
            $verifier->send(self::PHONE);
            self::fail('second send should lose the cooldown claim');
        } catch (RateLimitExceededException) {
        }

        self::assertSame(1, $rate->count('sms:rl:login_code:' . self::PHONE));
        self::assertSame(self::CODE, $store->get('code:login_code:' . self::PHONE));
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
        $code = (string) $store->get('code:login_code:' . self::PHONE);

        $result = $verifier->verify(self::PHONE, $code);
        self::assertTrue($result->ok);
        self::assertSame(VerifyResult::STATUS_OK, $result->status);
        // 成功后 code / 冷却 / 计数 / 发送痕迹全部清理，再校验视为没发过
        self::assertNull($store->get('code:login_code:' . self::PHONE));
        self::assertFalse($store->exists('cooldown:login_code:' . self::PHONE));
        self::assertFalse($store->exists('attempts:login_code:' . self::PHONE));
        self::assertFalse($store->exists('trace:login_code:' . self::PHONE));
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
        self::assertSame('1', $store->get('attempts:login_code:' . self::PHONE));
        // 未达上限，当前码仍可用
        self::assertNotNull($store->get('code:login_code:' . self::PHONE));
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
        self::assertNull($store->get('code:login_code:' . self::PHONE));
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
        self::assertTrue($store->exists('cooldown:login_code:' . self::PHONE));
        self::assertTrue($store->exists('cooldown:register_code:' . self::PHONE));
        self::assertSame(1, $rate->count('sms:rl:login_code:' . self::PHONE));
        self::assertSame(1, $rate->count('sms:rl:register_code:' . self::PHONE));

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
        $code = (string) $store->get('code:login_code:' . self::PHONE);

        self::assertTrue($verifier->verify(self::PHONE, $code, 'login_code')->ok);
        self::assertFalse($store->exists('cooldown:login_code:' . self::PHONE));
        self::assertTrue($store->exists('cooldown:register_code:' . self::PHONE));
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
        $store->forget('code:login_code:' . self::PHONE);

        $result = $verifier->verify(self::PHONE, self::CODE);

        self::assertSame(VerifyResult::STATUS_EXPIRED, $result->status);
        self::assertSame('验证码已过期，请重新获取', $result->message);
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
