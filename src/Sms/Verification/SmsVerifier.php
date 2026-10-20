<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Verification;

use Swoolefy\Library\Sms\Contracts\RateGuardInterface;
use Swoolefy\Library\Sms\Contracts\SmsStoreInterface;
use Swoolefy\Library\Sms\Contracts\VerifierInterface;
use Swoolefy\Library\Sms\Exception\InvalidConfigException;
use Swoolefy\Library\Sms\Exception\InvalidPhoneException;
use Swoolefy\Library\Sms\Exception\RateLimitExceededException;
use Swoolefy\Library\Sms\Exception\SmsException;
use Swoolefy\Library\Sms\Exception\SmsStorageException;
use Swoolefy\Library\Sms\SmsManager;
use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\Support\PhoneNormalizer;
use Swoolefy\Library\Sms\ValueObject\PhoneList;
use Swoolefy\Library\Sms\ValueObject\SendResult;
use Swoolefy\Library\Sms\ValueObject\VerifyResult;

/**
 * 短信验证码服务：生成 → 前置流控 → 存储 → 发送 → 校验。
 *
 * 设计：
 * - 限流复用 `RateGuardInterface`（生产 DurationRateGuard），存储复用 `SmsStoreInterface`（生产 RedisSmsStore）
 * - 与具体厂商 / Redis 解耦，全部依赖接口，可脱机用替身单测
 * - 先落码再发送，用户收到短信时码一定可验；发送失败/抛错会回滚验证码并释放冷却
 * - 防暴力：错误次数达上限即销毁当前码，需重新发送
 * - 成功校验后清理该场景的 code / attempts / cooldown，允许该场景立即重新获取
 * - 可选 `key_prefix` 隔离应用/环境，`hash_key` 对手机号与验证码做 HMAC，避免 Redis 暴露 PII / OTP 明文
 *
 * 协程安全：无请求态成员（除只读配置与协程安全依赖），可被 DI 请求级复用。
 */
final class SmsVerifier implements VerifierInterface
{
    private const TEMPLATE_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';

    private int $ttl;

    private int $maxVerify;

    private int $resendInterval;

    private bool $rateEnabled;

    private string $rateKeyTpl;

    private int $rateMax;

    private int $ratePeriod;

    private string $defaultRegion;

    private CodeGenerator $generator;

    private string $keyPrefix;

    private string $hashKey;

    /** @var list<string> */
    private array $allowedTemplates;

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
        $this->assertConfigKeys($config, 'verification');

        $this->ttl = (int) ($config['ttl'] ?? 300);
        if ($this->ttl < 1) {
            throw new InvalidConfigException('verification.ttl must be >= 1');
        }

        $this->maxVerify = (int) ($config['max_verify'] ?? 5);
        if ($this->maxVerify < 1) {
            throw new InvalidConfigException('verification.max_verify must be >= 1');
        }

        $this->resendInterval = (int) ($config['resend_interval'] ?? 60);
        if ($this->resendInterval < 0) {
            throw new InvalidConfigException('verification.resend_interval must be >= 0');
        }

        $rate = is_array($config['rate_limit'] ?? null) ? $config['rate_limit'] : [];
        $this->assertConfigKeys($rate, 'verification.rate_limit');
        $enabled = filter_var($rate['enabled'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new InvalidConfigException('verification.rate_limit.enabled must be a boolean');
        }
        $this->rateEnabled = $enabled;
        $this->rateKeyTpl = (string) ($rate['key'] ?? 'sms:rl:{template}:{phone}');
        $this->rateMax = (int) ($rate['max'] ?? 5);
        $this->ratePeriod = (int) ($rate['period'] ?? 3600);

        if ($this->rateEnabled) {
            if (!str_contains($this->rateKeyTpl, '{phone}')) {
                throw new InvalidConfigException('verification.rate_limit.key must contain {phone}');
            }
            if ($this->rateMax < 1) {
                throw new InvalidConfigException('verification.rate_limit.max must be >= 1');
            }
            if ($this->ratePeriod < 1) {
                throw new InvalidConfigException('verification.rate_limit.period must be >= 1');
            }
        }

        if (trim($this->codeParamKey) === '') {
            throw new InvalidConfigException('verification code param key must not be empty');
        }

        $this->keyPrefix = (string) ($config['key_prefix'] ?? '');
        $this->hashKey = (string) ($config['hash_key'] ?? '');
        if ($this->hashKey !== '' && strlen($this->hashKey) < 16) {
            throw new InvalidConfigException('verification.hash_key must be at least 16 characters');
        }

        $allowed = $config['allowed_templates'] ?? [];
        if (!is_array($allowed)) {
            throw new InvalidConfigException('verification.allowed_templates must be an array');
        }
        $this->allowedTemplates = [];
        foreach ($allowed as $template) {
            if (!is_string($template) || preg_match(self::TEMPLATE_PATTERN, $template) !== 1) {
                throw new InvalidConfigException(
                    'verification.allowed_templates must contain template names matching [A-Za-z0-9_.-]{1,64}'
                );
            }
            $this->allowedTemplates[] = $template;
        }

        // 裸 8 位香港/澳门号码靠它区分；与网关的 default_region 必须保持一致
        $region = $config['default_region'] ?? PhoneNormalizer::DEFAULT_REGION;
        $this->defaultRegion = PhoneNormalizer::normalizeRegion((string) $region);

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
        $this->assertTemplate($template);

        // 发厂商用去分隔符的写法，Redis 键用 E.164 canonical 写法：
        // 同一号码的 +86/86/裸 11 位、港/澳/台各写法必须落到同一套冷却、限流和验证码键。
        $wirePhone = PhoneList::make($phone)->first();
        $keyPhone = PhoneList::canonical($wirePhone, $this->defaultRegion);

        $cooldownKey = $this->cooldownKey($keyPhone, $template);
        $codeKey = $this->codeKey($keyPhone, $template);
        $claimed = false;
        $codeStored = false;
        $sent = false;

        try {
            // 先原子占住该场景冷却位，再扣小时配额。占不到就不发短信，也不扣配额。
            if ($this->resendInterval > 0) {
                $claimed = (bool) $this->infrastructure(
                    fn () => $this->store->add($cooldownKey, '1', $this->resendInterval)
                );
                if (!$claimed) {
                    $retryAfter = $this->remainingTtl($cooldownKey, $this->resendInterval);
                    throw new RateLimitExceededException(
                        sprintf('请 %d 秒后重试', $retryAfter),
                        RateLimitExceededException::REASON_COOLDOWN,
                        $retryAfter
                    );
                }
            }

            if ($this->rateEnabled) {
                $rateKey = $this->rateKey($keyPhone, $template);
                $exceeded = (bool) $this->infrastructure(
                    fn () => $this->rate->tooMany($rateKey, $this->rateMax, $this->ratePeriod)
                );
                if ($exceeded) {
                    $retryAfter = $this->remainingWindow($rateKey);
                    throw new RateLimitExceededException(
                        sprintf('短信发送过于频繁，请 %d 秒后重试', $retryAfter),
                        RateLimitExceededException::REASON_WINDOW,
                        $retryAfter
                    );
                }
            }

            $code = $this->generator->generate();

            // 先落码再发送：用户收到短信时码一定可验；发送失败/中断会在 catch 中回滚
            $this->infrastructure(
                fn () => $this->store->put($codeKey, $this->protectCode($code), $this->ttl)
            );
            $codeStored = true;

            $message = SmsMessage::make($wirePhone, $template, [$this->codeParamKey => $code]);
            $result = $this->gateway === null
                ? $this->sms->send($message)
                : $this->sms->sendVia($this->gateway, $message);

            if (!$result->ok) {
                $this->safeForget($codeKey);
                $codeStored = false;

                return $result;
            }

            $sent = true;
            // 短信已发出：冷却必须保留，后续记账失败只影响痕迹，不再改变发送结果
            $claimed = false;
            $this->safeForget($this->attemptsKey($keyPhone, $template));
            // 痕迹比验证码 TTL 更长。码过期后键没了，靠它区分 expired 和 absent
            $this->safePut($this->traceKey($keyPhone, $template), 'active', $this->traceTtl());
            // 发送成功后按完整间隔重写冷却，从发送完成起算；失败时 NX 占位仍有效
            if ($this->resendInterval > 0) {
                $this->safePut($cooldownKey, '1', $this->resendInterval);
            }

            return $result;
        } catch (\Throwable $e) {
            // 短信未成功发出时不留下可用码；已发出的码必须保留，供用户立即校验
            if ($codeStored && !$sent) {
                $this->safeForget($codeKey);
            }

            throw $e;
        } finally {
            // 清理失败不能覆盖原始异常或发送结果
            if ($claimed) {
                $this->safeForget($cooldownKey);
            }
        }
    }

    /**
     * 校验验证码。读取、比较、消费、计数、锁定与清理由存储层原子完成，
     * 并发下同一验证码至多成功一次，失败比较次数不超过 max_verify。
     *
     * - 没有码：看发送痕迹，locked=已作废，active=已过期，都没有=没发过
     * - 比对成功：清掉该场景的码、次数、冷却、痕迹
     * - 比对失败：累计次数；达到 max_verify 则删码并把痕迹标成 locked
     */
    public function verify(string $phone, string $input, string $template = 'login_code'): VerifyResult
    {
        $this->assertTemplate($template);

        // verify 与 send 使用同一 canonical 号码键；非法号码在访问存储前即抛错
        $keyPhone = PhoneList::canonical(PhoneList::make($phone)->first(), $this->defaultRegion);
        $input = trim($input);

        $result = $this->infrastructure(fn () => $this->store->verifyCode(
            $this->codeKey($keyPhone, $template),
            $this->attemptsKey($keyPhone, $template),
            $this->traceKey($keyPhone, $template),
            $this->cooldownKey($keyPhone, $template),
            $input === '' ? '' : $this->protectCode($input),
            $this->maxVerify,
            $this->ttl,
            $this->traceTtl(),
        ));

        return match ($result['status']) {
            VerifyResult::STATUS_OK => VerifyResult::ok(),
            VerifyResult::STATUS_LOCKED => VerifyResult::locked(),
            VerifyResult::STATUS_EXPIRED => VerifyResult::expired(),
            VerifyResult::STATUS_ABSENT => VerifyResult::absent(),
            VerifyResult::STATUS_MISMATCH => VerifyResult::mismatch($result['remaining']),
            default => VerifyResult::absent(),
        };
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
        return $this->key('code', $phone, $template);
    }

    private function cooldownKey(string $phone, string $template): string
    {
        return $this->key('cooldown', $phone, $template);
    }

    private function attemptsKey(string $phone, string $template): string
    {
        return $this->key('attempts', $phone, $template);
    }

    private function traceKey(string $phone, string $template): string
    {
        return $this->key('trace', $phone, $template);
    }

    private function key(string $kind, string $phone, string $template): string
    {
        return $this->keyPrefix . $kind . ':' . $template . ':' . $this->phoneId($phone);
    }

    /**
     * 手机号入键前可选 HMAC，避免 Redis 备份 / SCAN / 慢日志暴露完整号码。
     */
    private function phoneId(string $phone): string
    {
        if ($this->hashKey === '') {
            return $phone;
        }

        return hash_hmac('sha256', $phone, $this->hashKey . '|phone');
    }

    /**
     * 验证码存储值可选 HMAC（低熵 OTP 必须带服务端密钥，避免被彩虹表还原）。
     */
    private function protectCode(string $code): string
    {
        if ($this->hashKey === '') {
            return $code;
        }

        return hash_hmac('sha256', $code, $this->hashKey . '|otp');
    }

    private function rateKey(string $phone, string $template): string
    {
        $key = str_replace(['{template}', '{phone}'], [$template, $this->phoneId($phone)], $this->rateKeyTpl);
        if (!str_contains($this->rateKeyTpl, '{template}')) {
            $key = $template . ':' . $key;
        }

        if ($this->keyPrefix !== '' && !str_starts_with($key, $this->keyPrefix)) {
            $key = $this->keyPrefix . $key;
        }

        return $key;
    }

    /**
     * 带前后空白的键会被 PHP 当成另一个键而静默落回默认值，必须直接报错。
     *
     * @param array<array-key, mixed> $config
     */
    private function assertConfigKeys(array $config, string $scope): void
    {
        foreach ($config as $key => $_) {
            if (is_string($key) && $key !== trim($key)) {
                throw new InvalidConfigException(sprintf(
                    '%s config key [%s] must not contain leading or trailing whitespace',
                    $scope,
                    $key
                ));
            }
        }
    }

    private function assertTemplate(string $template): void
    {
        if (preg_match(self::TEMPLATE_PATTERN, $template) !== 1) {
            throw new InvalidConfigException(
                'Sms verification template must match [A-Za-z0-9_.-]{1,64}'
            );
        }

        if ($this->allowedTemplates !== [] && !in_array($template, $this->allowedTemplates, true)) {
            throw new InvalidConfigException(
                "Sms verification template [{$template}] is not in allowed_templates"
            );
        }
    }

    /**
     * 存储 / 限流底层异常统一为 SmsStorageException，保证业务 catch (SmsException) 可覆盖。
     */
    private function infrastructure(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (SmsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SmsStorageException(
                'Sms infrastructure operation failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function remainingTtl(string $key, int $fallback): int
    {
        try {
            $ttl = $this->store->ttl($key);
        } catch (\Throwable) {
            return max(1, $fallback);
        }

        return $ttl > 0 ? $ttl : max(1, $fallback);
    }

    /**
     * 窗口命中时查询最早一次消费滑出窗口的秒数；查询失败退回完整周期。
     */
    private function remainingWindow(string $key): int
    {
        try {
            $remaining = $this->rate->remaining($key, $this->ratePeriod);
        } catch (\Throwable) {
            return $this->ratePeriod;
        }

        return max(1, min($remaining, $this->ratePeriod));
    }

    private function safeForget(string $key): void
    {
        try {
            $this->store->forget($key);
        } catch (\Throwable) {
            // 清理是尽力而为，不能覆盖原始异常 / 返回值
        }
    }

    private function safePut(string $key, string $value, int $ttl): void
    {
        try {
            $this->store->put($key, $value, $ttl);
        } catch (\Throwable) {
            // 短信已发出后的记账是尽力而为，不能把成功发送改判为失败
        }
    }
}
