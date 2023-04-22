<?php
declare(strict_types=1);

namespace Lcobucci\JWT\Validation\Constraint;

use DateInterval;
use DateTimeInterface;
use Common\Library\Jwt\Token;
use Common\Library\Jwt\Validation\ConstraintViolation;
use Common\Library\Jwt\Validation\ValidAt as ValidAtInterface;
use Psr\Clock\ClockInterface as Clock;

final class LooseValidAt implements ValidAtInterface
{
    /**
     * @var DateInterval
     */
    private $leeway;

    /**
     * @var Clock
     */
    protected $clock;

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
            throw ConstraintViolation::error('The token is expired', $this);
        }
    }

    /** @throws ConstraintViolation */
    private function assertMinimumTime(Token $token, DateTimeInterface $now): void
    {
        if (! $token->isMinimumTimeBefore($now)) {
            throw ConstraintViolation::error('The token cannot be used yet', $this);
        }
    }

    /**
     * @param Token $token
     * @param DateTimeInterface $now
     * @return void
     */
    private function assertIssueTime(Token $token, DateTimeInterface $now): void
    {
        if (! $token->hasBeenIssuedBefore($now)) {
            throw ConstraintViolation::error('The token was issued in the future', $this);
        }
    }
}