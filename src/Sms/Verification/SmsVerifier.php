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

/**
 * 短信验证码服务：生成 → 前置流控 → 发送 → 存储 → 校验。
 *
 * 设计：
 * - 限流复用 `RateGuardInterface`（生产 DurationRateGuard），存储复用 `SmsStoreInterface`（生产 RedisSmsStore）
 * - 与具体厂商 / Redis 解耦，全部依赖接口，可脱机用替身单测
 * - 防暴力：错误次数达上限即销毁当前码，需重新发送
 * - 成功校验后一并清理 code / attempts / cooldown，允许立即重新获取
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
     * @param array<string, mixed> $config verification 配置段
     * @param CodeGenerator|null $generator 不传则按 config length/type 构建
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
        $this->rateKeyTpl = (string) ($rate['key'] ?? 'sms:rl:{phone}');
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

        // 先看重发间隔。限流器在未超限时就会扣一次配额，不能让冷却期内的重试把小时窗口打满
        if ($this->resendInterval > 0 && $this->store->exists($this->cooldownKey($phone))) {
            throw new RateLimitExceededException(sprintf('请 %d 秒后重试', $this->resendInterval));
        }

        if ($this->rateEnabled && $this->rate->tooMany($this->rateKey($phone), $this->rateMax, $this->ratePeriod)) {
            throw new RateLimitExceededException('短信发送过于频繁，请稍后再试');
        }

        $code = $this->generator->generate();
        $message = SmsMessage::make($phone, $template, [$this->codeParamKey => $code]);
        $result = $this->gateway === null
            ? $this->sms->send($message)
            : $this->sms->sendVia($this->gateway, $message);

        if ($result->ok) {
            $this->store->put($this->codeKey($phone, $template), $code, $this->ttl);
            if ($this->resendInterval > 0) {
                $this->store->put($this->cooldownKey($phone), '1', $this->resendInterval);
            }
            $this->store->forget($this->attemptsKey($phone, $template));
        }

        return $result;
    }

    /**
     * 校验验证码；成功即销毁，失败累计并在超上限后失效。
     */
    public function verify(string $phone, string $input, string $template = 'login_code'): bool
    {
        $stored = $this->store->get($this->codeKey($phone, $template));
        if ($stored === null) {
            // 不存在 / 已过期 / 已被锁
            return false;
        }

        $input = trim($input);
        if ($input !== '' && hash_equals($stored, $input)) {
            $this->store->forget($this->codeKey($phone, $template));
            $this->store->forget($this->attemptsKey($phone, $template));
            $this->store->forget($this->cooldownKey($phone));

            return true;
        }

        // 错误：累计次数，达上限销毁当前码（强制重新发送）
        $attempts = $this->store->increment($this->attemptsKey($phone, $template), $this->ttl);
        if ($this->maxVerify > 0 && $attempts >= $this->maxVerify) {
            $this->store->forget($this->codeKey($phone, $template));
        }

        return false;
    }

    private function codeKey(string $phone, string $template): string
    {
        return 'code:' . $template . ':' . $phone;
    }

    private function cooldownKey(string $phone): string
    {
        return 'cooldown:' . $phone;
    }

    private function attemptsKey(string $phone, string $template): string
    {
        return 'attempts:' . $template . ':' . $phone;
    }

    private function rateKey(string $phone): string
    {
        return str_replace('{phone}', $phone, $this->rateKeyTpl);
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
