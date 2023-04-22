<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Common\Library\Clock;

use DateTimeImmutable;
use DateTimeZone;

final class FrozenClock implements Clock
{
    /**
     * @var DateTimeImmutable
     */
    private $now;

    /**
     * @param DateTimeImmutable $now
     */
    public function __construct(DateTimeImmutable $now)
    {
        $this->now = $now;
    }

    public static function fromUTC(): self
    {
        return new self(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public function setTo(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
