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


namespace Common\Library\Jwt\Validation\Constraint;

use DateInterval;
use DateTimeInterface;
use Common\Library\Clock\Clock;
use Common\Library\Jwt\Token;
use Common\Library\Jwt\Validation\ConstraintViolation;

final class ValidAt implements \Common\Library\Jwt\Validation\ValidAt
{
    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var DateInterval
     */
    private $leeway;

    public function __construct(?Clock $clock = null, ?DateInterval $leeway = null)
    {
        if($clock) {
            $this->clock  = $clock;
        }else {
            $this->clock = \Common\Library\Clock\SystemClock::fromSystemTimezone();
        }

        $this->leeway = $this->guardLeeway($leeway);
    }

    private function guardLeeway(?DateInterval $leeway): DateInterval
    {
        if ($leeway === null) {
            return new DateInterval('PT0S');
        }

        if ($leeway->invert === 1) {
            throw LeewayCannotBeNegative::create();
        }

        return $leeway;
    }

    public function assert(Token $token): void
    {
        $now = $this->clock->now();

        $this->assertIssueTime($token, $now->add($this->leeway));
        $this->assertMinimumTime($token, $now->add($this->leeway));
        $this->assertExpiration($token, $now->sub($this->leeway));
    }

    /** @throws ConstraintViolation */
    private function assertExpiration(Token $token, DateTimeInterface $now): void
    {
        if ($token->isExpired($now)) {
            throw new ConstraintViolation('The token is expired');
        }
    }

    /** @throws ConstraintViolation */
    private function assertMinimumTime(Token $token, DateTimeInterface $now): void
    {
        if (! $token->isMinimumTimeBefore($now)) {
            throw new ConstraintViolation('The token cannot be used yet');
        }
    }

    /** @throws ConstraintViolation */
    private function assertIssueTime(Token $token, DateTimeInterface $now): void
    {
        if (! $token->hasBeenIssuedBefore($now)) {
            throw new ConstraintViolation('The token was issued in the future');
        }
    }
}
