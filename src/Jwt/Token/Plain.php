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


namespace Common\Library\Jwt\Token;

use DateTimeInterface;
use Common\Library\Jwt\Token as TokenInterface;

final class Plain implements TokenInterface
{
    /**
     * @var DataSet
     */
    private $headers;

    /**
     * @var DataSet
     */
    private $claims;

    /**
     * @var Signature
     */
    private $signature;

    public function __construct(
        DataSet $headers,
        DataSet $claims,
        Signature $signature
    ) {
        $this->headers   = $headers;
        $this->claims    = $claims;
        $this->signature = $signature;
    }

    public function headers(): DataSet
    {
        return $this->headers;
    }

    public function claims(): DataSet
    {
        return $this->claims;
    }

    public function signature(): Signature
    {
        return $this->signature;
    }

    public function payload(): string
    {
        return $this->headers->toString() . '.' . $this->claims->toString();
    }

    public function isPermittedFor(string $audience): bool
    {
        return in_array($audience, $this->claims->get(RegisteredClaims::AUDIENCE, []), true);
    }

    public function isIdentifiedBy(string $id): bool
    {
        return $this->claims->get(RegisteredClaims::ID) === $id;
    }

    public function isRelatedTo(string $subject): bool
    {
        return $this->claims->get(RegisteredClaims::SUBJECT) === $subject;
    }

    public function hasBeenIssuedBy(string ...$issuers): bool
    {
        return in_array($this->claims->get(RegisteredClaims::ISSUER), $issuers, true);
    }

    public function hasBeenIssuedBefore(DateTimeInterface $now): bool
    {
        return $now >= $this->claims->get(RegisteredClaims::ISSUED_AT);
    }

    public function isMinimumTimeBefore(DateTimeInterface $now): bool
    {
        return $now >= $this->claims->get(RegisteredClaims::NOT_BEFORE);
    }

    public function isExpired(DateTimeInterface $now): bool
    {
        if (! $this->claims->has(RegisteredClaims::EXPIRATION_TIME)) {
            return false;
        }

        return $now >= $this->claims->get(RegisteredClaims::EXPIRATION_TIME);
    }

    public function toString(): string
    {
        return $this->headers->toString() . '.'
             . $this->claims->toString() . '.'
             . $this->signature->toString();
    }
}
