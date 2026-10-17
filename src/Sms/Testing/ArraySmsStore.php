<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Testing;

use Swoolefy\Library\Sms\Contracts\SmsStoreInterface;

/**
 * 内存版验证码存储：无 Redis、无 IO，供单测确定性断言。
 *
 * 语义对齐 RedisSmsStore：get 未命中返回 null；increment 首次创建补 TTL；exists 判存在。
 * TTL 在测试中不做真实时间衰减（用例可手动 unset），仅记录以便断言配置正确。
 */
final class ArraySmsStore implements SmsStoreInterface
{
    /** @var array<string, string> */
    private array $data = [];

    /** @var array<string, int> 记录每个键最后一次写入的 TTL，便于断言 */
    private array $ttls = [];

    public function put(string $key, string $value, int $ttl): void
    {
        $this->data[$key] = $value;
        $this->ttls[$key] = $ttl;
    }

    public function get(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key], $this->ttls[$key]);
    }

    public function exists(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function increment(string $key, int $ttl): int
    {
        $current = (int) ($this->data[$key] ?? 0) + 1;
        $this->data[$key] = (string) $current;
        $this->ttls[$key] = $ttl;

        return $current;
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
