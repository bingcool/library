<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\ValueObject;

/**
 * 验证码校验结果。业务按 status 区分情况，message 可直接回给前端。
 *
 * - ok：比对成功。码、错误次数、该场景冷却和发送痕迹已删除。
 * - mismatch：输错，当前码还在。remaining 是还可尝试的次数；不限次数时为 null。
 * - expired：这个场景发过码，但验证码已过 TTL。
 * - absent：这个场景没有发送记录（没发过，或发送痕迹也过期了）。
 * - locked：错误次数达到 max_verify，当前码已作废。remaining 为 0。
 */
final class VerifyResult
{
    /** 校验通过 */
    public const STATUS_OK = 'ok';

    /** 输错，码仍有效，看 remaining */
    public const STATUS_MISMATCH = 'mismatch';

    /** 发过且已过期，需要重新获取 */
    public const STATUS_EXPIRED = 'expired';

    /** 该场景没发过码 */
    public const STATUS_ABSENT = 'absent';

    /** 错误次数用尽，码已作废，需要重新获取 */
    public const STATUS_LOCKED = 'locked';

    /**
     * @param bool $ok 是否校验通过
     * @param string $status ok|mismatch|expired|absent|locked
     * @param int|null $remaining 输错时还可尝试的次数；已作废为 0；其余为 null
     * @param string $message 可直接回给前端的说明
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly ?int $remaining = null,
        public readonly string $message = '',
    ) {
    }

    /** 比对成功。 */
    public static function ok(): self
    {
        return new self(true, self::STATUS_OK, null, '验证通过');
    }

    /**
     * 输入与当前码不一致。
     *
     * @param int|null $remaining 还可尝试次数；max_verify <= 0 时不限制，传 null
     */
    public static function mismatch(?int $remaining): self
    {
        $message = $remaining === null
            ? '验证码错误'
            : sprintf('验证码错误，还可尝试 %d 次', $remaining);

        return new self(false, self::STATUS_MISMATCH, $remaining, $message);
    }

    /** 验证码键已消失，但发送痕迹仍是 active。 */
    public static function expired(): self
    {
        return new self(false, self::STATUS_EXPIRED, null, '验证码已过期，请重新获取');
    }

    /** 没有验证码，也没有发送痕迹。 */
    public static function absent(): self
    {
        return new self(false, self::STATUS_ABSENT, null, '请先获取验证码');
    }

    /** 错误次数已达上限，或发送痕迹已标成 locked。 */
    public static function locked(): self
    {
        return new self(false, self::STATUS_LOCKED, 0, '验证码已失效，请重新获取');
    }
}
