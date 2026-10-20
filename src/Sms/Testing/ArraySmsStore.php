<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Testing;

use Swoolefy\Library\Sms\Contracts\SmsStoreInterface;
use Swoolefy\Library\Sms\ValueObject\VerifyResult;

/**
 * 内存版验证码存储：无 Redis、无 IO，供单测确定性断言。
 *
 * 语义对齐 RedisSmsStore：get 未命中返回 null；increment 首次创建补 TTL（已有键缺失 TTL 时修复）；
 * ttl 返回剩余秒数。TTL 走虚拟时钟，用例可用 `advance()` 推进时间模拟过期，无需真实等待。
 */
class ArraySmsStore implements SmsStoreInterface
{
    /** @var array<string, string> */
    private array $data = [];

    /** @var array<string, int> 记录每个键最后一次写入的 TTL，便于断言 */
    private array $ttls = [];

    /** @var array<string, int> 绝对过期时刻（虚拟时钟） */
    private array $expires = [];

    private int $now = 0;

    /**
     * 推进虚拟时钟（秒），使到期键在后续读写时失效。
     */
    public function advance(int $seconds): void
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('ArraySmsStore clock can only move forward');
        }

        $this->now += $seconds;
    }

    public function put(string $key, string $value, int $ttl): void
    {
        $this->data[$key] = $value;
        $this->ttls[$key] = $ttl;
        $this->expires[$key] = $this->now + $ttl;
    }

    public function add(string $key, string $value, int $ttl): bool
    {
        $this->purge($key);
        if (isset($this->data[$key])) {
            return false;
        }

        $this->put($key, $value, $ttl);

        return true;
    }

    public function get(string $key): ?string
    {
        $this->purge($key);

        return $this->data[$key] ?? null;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key], $this->ttls[$key], $this->expires[$key]);
    }

    public function exists(string $key): bool
    {
        $this->purge($key);

        return isset($this->data[$key]);
    }

    public function ttl(string $key): int
    {
        $this->purge($key);
        if (!isset($this->data[$key])) {
            return -2;
        }

        // 所有写入路径都会记录过期时刻，不存在「键在但无 TTL」的状态
        return $this->expires[$key] - $this->now;
    }

    public function increment(string $key, int $ttl): int
    {
        $this->purge($key);
        $current = (int) ($this->data[$key] ?? 0) + 1;
        $this->data[$key] = (string) $current;

        // 首次创建或历史键缺 TTL 时补上；已有 TTL 不因自增而延长
        if ($current === 1 || !isset($this->expires[$key])) {
            $this->ttls[$key] = $ttl;
            $this->expires[$key] = $this->now + $ttl;
        }

        return $current;
    }

    private function purge(string $key): void
    {
        if (isset($this->expires[$key]) && $this->now >= $this->expires[$key]) {
            unset($this->data[$key], $this->ttls[$key], $this->expires[$key]);
        }
    }

    public function verifyCode(
        string $codeKey,
        string $attemptsKey,
        string $traceKey,
        string $cooldownKey,
        string $input,
        int $maxAttempts,
        int $attemptTtl,
        int $traceTtl,
    ): array {
        $this->purge($codeKey);
        $this->purge($traceKey);
        $stored = $this->data[$codeKey] ?? null;

        if ($stored === null) {
            $trace = $this->data[$traceKey] ?? null;
            if ($trace === 'locked') {
                return ['status' => VerifyResult::STATUS_LOCKED, 'remaining' => 0];
            }
            if ($trace === 'active') {
                return ['status' => VerifyResult::STATUS_EXPIRED, 'remaining' => null];
            }

            return ['status' => VerifyResult::STATUS_ABSENT, 'remaining' => null];
        }

        if ($input !== '' && hash_equals($stored, $input)) {
            $this->forget($codeKey);
            $this->forget($attemptsKey);
            $this->forget($cooldownKey);
            $this->forget($traceKey);

            return ['status' => VerifyResult::STATUS_OK, 'remaining' => null];
        }

        // 无 IO / 无协程切换，整个方法在单进程内即为原子操作
        $attempts = $this->increment($attemptsKey, $attemptTtl);
        if ($maxAttempts > 0 && $attempts >= $maxAttempts) {
            $this->forget($codeKey);
            $this->put($traceKey, 'locked', $traceTtl);

            return ['status' => VerifyResult::STATUS_LOCKED, 'remaining' => 0];
        }

        $remaining = $maxAttempts > 0 ? $maxAttempts - $attempts : null;

        return ['status' => VerifyResult::STATUS_MISMATCH, 'remaining' => $remaining];
    }

    public function lastTtl(string $key): ?int
    {
        return $this->ttls[$key] ?? null;
    }

    /**
     * 导出当前快照，便于调试断言。
     *
     * @return array<string, string>
     */
    public function dump(): array
    {
        return $this->data;
    }
}
