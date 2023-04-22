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

final class SystemClock implements Clock
{

    /**
     * @var DateTimeZone
     */
    private $timezone;

    /**
     * @param DateTimeZone $timezone
     */
    public function __construct(DateTimeZone $timezone)
    {
        $this->timezone = $timezone;
    }

    public static function fromUTC(): self
    {
        return new self(new DateTimeZone('UTC'));
    }

    public static function fromSystemTimezone(): self
    {
        return new self(new DateTimeZone(date_default_timezone_get()));
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
