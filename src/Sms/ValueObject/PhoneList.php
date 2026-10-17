<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\ValueObject;

use Swoolefy\Library\Sms\Exception\InvalidPhoneException;

/**
 * 归一化、去重、校验后的手机号集合。厂商无关。
 *
 * 职责：
 * - 去空白、去重复
 * - 号码格式校验（E.164 风格：可选 + ，6~15 位数字）
 * - 单次发送数量上限（默认 1000，贴近阿里云单请求上限）
 *
 * 不可变：构造后即固定，`to()` / `toArray()` 只读。
 */
final class PhoneList implements \Countable, \IteratorAggregate
{
    /** 默认单次发送号码上限 */
    public const DEFAULT_MAX = 1000;

    /** 允许 +国际区号，6~15 位数字（覆盖国内 11 位与国际号码） */
    private const PATTERN = '#^\+?\d{6,15}$#';

    /** @var list<string> */
    private array $phones;

    /**
     * @param string|int|array<array-key, string|int> $input 单号码或号码数组
     * @param int $max 单次数量上限
     * @throws InvalidPhoneException 空集合 / 非法号码 / 超上限
     */
    public function __construct(string|int|array $input, private int $max = self::DEFAULT_MAX)
    {
        $raw = is_array($input) ? $input : [$input];

        $seen = [];
        $list = [];
        foreach ($raw as $phone) {
            // 去空格与常见的分隔噪声；(string) 兼容传 int 号码
            $phone = preg_replace('/[\s\-()]/', '', (string) $phone) ?? '';
            if ($phone === '') {
                continue;
            }
            if (!preg_match(self::PATTERN, $phone)) {
                throw new InvalidPhoneException(sprintf('Invalid phone number: %s', $phone));
            }
            // 去重，保序
            if (!isset($seen[$phone])) {
                $seen[$phone] = true;
                $list[] = $phone;
            }
        }

        if ($list === []) {
            throw new InvalidPhoneException('Phone list is empty');
        }
        if ($this->max > 0 && count($list) > $this->max) {
            throw new InvalidPhoneException(sprintf('Too many phones in one request: %d > %d', count($list), $this->max));
        }

        $this->phones = $list;
    }

    /**
     * @param string|int|array<array-key, string|int> $input
     * @param int $max
     */
    public static function make(string|int|array $input, int $max = self::DEFAULT_MAX): self
    {
        return new self($input, $max);
    }

    /**
     * 全部号码（保序去重后）。
     *
     * @return list<string>
     */
    public function toArray(): array
    {
        return $this->phones;
    }

    /**
     * 逗号拼接，适配阿里云 PhoneNumbers 等「单串多号码」入参。
     */
    public function toString(): string
    {
        return implode(',', $this->phones);
    }

    public function first(): string
    {
        return $this->phones[0];
    }

    public function count(): int
    {
        return count($this->phones);
    }

    /**
     * @return \ArrayIterator<int, string>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->phones);
    }
}
