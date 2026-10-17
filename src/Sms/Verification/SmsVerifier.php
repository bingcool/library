<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Verification;

use Swoolefy\Library\Sms\Contracts\RateGuardInterface;
use Swoolefy\Library\Sms\Contracts\SmsStoreInterface;
use Swoolefy\Library\Sms\Contracts\VerifierInterface;
use Swoolefy\Library\Sms\Exception\InvalidPhoneException;
use Swoolefy\Library\Sms\Exception\RateLimitExceededException;
use Swoolefy\Library\Sms\SmsManager;
use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\ValueObject\PhoneList;
use Swoolefy\Library\Sms\ValueObject\SendResult;
use Swoolefy\Library\Sms\ValueObject\VerifyResult;

/**
 * 短信验证码服务：生成 → 前置流控 → 发送 → 存储 → 校验。
 *
 * 设计：
 * - 限流复用 `RateGuardInterface`（生产 DurationRateGuard），存储复用 `SmsStoreInterface`（生产 RedisSmsStore）
 * - 与具体厂商 / Redis 解耦，全部依赖接口，可脱机用替身单测
 * - 防暴力：错误次数达上限即销毁当前码，需重新发送
 * - 成功校验后清理该场景的 code / attempts / cooldown，允许该场景立即重新获取
 *
 * 协程安全：无请求态成员（除只读配置与协程安全依赖），可被 DI 请求级复用。
 */
final class SmsVerifier implements VerifierInterface
{
    private int $ttl;

    private int $maxVerify;

    private int $resendInterval;

    private bool $rateEnabled;

    private string $rateKeyTpl;

    private int $rateMax;

    private int $ratePeriod;

    private CodeGenerator $generator;

    /**
     * @param SmsManager $sms
     * @param SmsStoreInterface $store
     * @param RateGuardInterface $rate
     * @param array $config
     * @param CodeGenerator|null $generator
     * @param string|null $gateway
     * @param string $codeParamKey
     */
    public function __construct(
        private SmsManager $sms,
        private SmsStoreInterface $store,
        private RateGuardInterface $rate,
        array $config,
        ?CodeGenerator $generator = null,
        private ?string $gateway = null,
        private string $codeParamKey = 'code',
    ) {
        // 容错：修正配置里可能存在的键名空格（如 ' resend_interval'）
        $config = $this->normalizeKeys($config);

        $this->ttl = (int) ($config['ttl'] ?? 300);
        $this->maxVerify = (int) ($config['max_verify'] ?? 5);
        $this->resendInterval = (int) ($config['resend_interval'] ?? 60);

        $rate = is_array($config['rate_limit'] ?? null) ? $config['rate_limit'] : [];
        $this->rateEnabled = (bool) ($rate['enabled'] ?? true);
        $this->rateKeyTpl = (string) ($rate['key'] ?? 'sms:rl:{template}:{phone}');
        $this->rateMax = (int) ($rate['max'] ?? 5);
        $this->ratePeriod = (int) ($rate['period'] ?? 3600);

        $this->generator = $generator ?? new CodeGenerator(
            (int) ($config['length'] ?? 6),
            (string) ($config['type'] ?? 'number'),
        );
    }

    /**
     * 生成并发送验证码。
     *
     * @throws InvalidPhoneException 号码非法
     * @throws RateLimitExceededException 命中窗口限流或重发间隔
     */
    public function send(string $phone, string $template = 'login_code'): SendResult
    {
        // 复用 PhoneList 做号码校验
        PhoneList::make($phone);

        // 先原子占住该场景冷却位，再扣小时配额。占不到就不发短信，也不扣配额。
        // 发送失败或中途抛错会放开冷却，避免用户没收到码却要空等。
        $cooldownKey = $this->cooldownKey($phone, $template);
        $claimed = false;
        try {
            if ($this->resendInterval > 0) {
                $claimed = $this->store->add($cooldownKey, '1', $this->resendInterval);
                if (!$claimed) {
                    throw new RateLimitExceededException(sprintf('请 %d 秒后重试', $this->resendInterval));
                }
            }

            if ($this->rateEnabled && $this->rate->tooMany($this->rateKey($phone, $template), $this->rateMax, $this->ratePeriod)) {
                throw new RateLimitExceededException('短信发送过于频繁，请稍后再试');
            }

            $code = $this->generator->generate();
            $message = SmsMessage::make($phone, $template, [$this->codeParamKey => $code]);
            $result = $this->gateway === null
                ? $this->sms->send($message)
                : $this->sms->sendVia($this->gateway, $message);

            if ($result->ok) {
                $this->store->put($this->codeKey($phone, $template), $code, $this->ttl);
                $this->store->forget($this->attemptsKey($phone, $template));
                // 痕迹比验证码 TTL 更长。码过期后键没了，靠它区分 expired 和 absent
                $this->store->put($this->traceKey($phone, $template), 'active', $this->traceTtl());
                // 发送成功后按完整间隔重写冷却，从发送完成起算
                if ($claimed) {
                    $this->store->put($cooldownKey, '1', $this->resendInterval);
                    $claimed = false;
                }
            }

            return $result;
        } finally {
            if ($claimed) {
                $this->store->forget($cooldownKey);
            }
        }
    }

    /**
     * 校验验证码。
     *
     * 判断顺序：
     * 1. 没有码：看发送痕迹，locked=已作废，active=已过期，都没有=没发过
     * 2. 比对成功：清掉该场景的码、次数、冷却、痕迹
     * 3. 比对失败：累计次数；达到 max_verify 则删码并把痕迹标成 locked
     */
    public function verify(string $phone, string $input, string $template = 'login_code'): VerifyResult
    {
        $stored = $this->store->get($this->codeKey($phone, $template));
        if ($stored === null) {
            return $this->missingCodeResult($phone, $template);
        }

        $input = trim($input);
        if ($input !== '' && hash_equals($stored, $input)) {
            $this->store->forget($this->codeKey($phone, $template));
            $this->store->forget($this->attemptsKey($phone, $template));
            $this->store->forget($this->cooldownKey($phone, $template));
            $this->store->forget($this->traceKey($phone, $template));

            return VerifyResult::ok();
        }

        // 空串和错误码都计一次。未达上限时正确码仍然有效
        $attempts = $this->store->increment($this->attemptsKey($phone, $template), $this->ttl);
        if ($this->maxVerify > 0 && $attempts >= $this->maxVerify) {
            $this->store->forget($this->codeKey($phone, $template));
            // 码删掉后下次再验，靠 locked 痕迹继续返回已作废，而不是没发过
            $this->store->put($this->traceKey($phone, $template), 'locked', $this->traceTtl());

            return VerifyResult::locked();
        }

        // max_verify <= 0 表示不限制错误次数，remaining 留空
        $remaining = $this->maxVerify > 0 ? $this->maxVerify - $attempts : null;

        return VerifyResult::mismatch($remaining);
    }

    /**
     * 验证码键不在时的结果。不能把过期、没发过、已作废都当成同一种失败。
     */
    private function missingCodeResult(string $phone, string $template): VerifyResult
    {
        $trace = $this->store->get($this->traceKey($phone, $template));
        if ($trace === 'locked') {
            return VerifyResult::locked();
        }
        if ($trace === 'active') {
            return VerifyResult::expired();
        }

        return VerifyResult::absent();
    }

    /**
     * 发送痕迹比验证码活得更久，码过期后仍能区分「过期」和「没发过」。
     */
    private function traceTtl(): int
    {
        return max($this->ttl * 2, $this->ratePeriod, 0);
    }

    private function codeKey(string $phone, string $template): string
    {
        return 'code:' . $template . ':' . $phone;
    }

    private function cooldownKey(string $phone, string $template): string
    {
        return 'cooldown:' . $template . ':' . $phone;
    }

    private function attemptsKey(string $phone, string $template): string
    {
        return 'attempts:' . $template . ':' . $phone;
    }

    private function traceKey(string $phone, string $template): string
    {
        return 'trace:' . $template . ':' . $phone;
    }

    private function rateKey(string $phone, string $template): string
    {
        $key = str_replace(['{template}', '{phone}'], [$template, $phone], $this->rateKeyTpl);
        if (!str_contains($this->rateKeyTpl, '{template}')) {
            $key = $template . ':' . $key;
        }

        return $key;
    }

    /**
     * 去掉配置键两端空白，容忍 ' resend_interval' 这类书写瑕疵。
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeKeys(array $config): array
    {
        $out = [];
        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $key = trim($key);
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
