<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Sms\Exception\RateLimitExceededException;
use Swoolefy\Library\Sms\SmsManager;
use Swoolefy\Library\Sms\Testing\ArrayRateGuard;
use Swoolefy\Library\Sms\Testing\ArraySmsStore;
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
            'rate_limit' => ['enabled' => true, 'key' => 'sms:rl:{phone}', 'max' => 3, 'period' => 3600],
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
        self::assertTrue($store->exists('cooldown:' . self::PHONE));
    }

    public function testSendDoesNotStoreWhenGatewayFails(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate, ['gateway' => 'log', 'simulate' => 'failure']);

        $result = $verifier->send(self::PHONE);

        self::assertFalse($result->ok);
        self::assertNull($store->get('code:login_code:' . self::PHONE));
        self::assertFalse($store->exists('cooldown:' . self::PHONE));
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

        self::assertTrue($verifier->verify(self::PHONE, $code));
        // 成功后 code / 冷却 / 计数全部清理，允许立即重新获取
        self::assertNull($store->get('code:login_code:' . self::PHONE));
        self::assertFalse($store->exists('cooldown:' . self::PHONE));
        self::assertFalse($store->exists('attempts:login_code:' . self::PHONE));
    }

    public function testVerifyWrongCodeFailsAndCountsAttempts(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        $verifier->send(self::PHONE);

        self::assertFalse($verifier->verify(self::PHONE, '000000'));
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

        self::assertFalse($verifier->verify(self::PHONE, '111111'));
        self::assertFalse($verifier->verify(self::PHONE, '222222'));
        // 达上限：当前码被销毁，需重新发送（防暴力）
        self::assertNull($store->get('code:login_code:' . self::PHONE));
        // 即便用正确码也不再通过
        self::assertFalse($verifier->verify(self::PHONE, self::CODE));
    }

    public function testVerifyUnknownPhoneReturnsFalse(): void
    {
        $store = new ArraySmsStore();
        $rate = new ArrayRateGuard();
        $verifier = $this->makeVerifier($store, $rate);

        self::assertFalse($verifier->verify('13900009000', '123456'));
    }
}
