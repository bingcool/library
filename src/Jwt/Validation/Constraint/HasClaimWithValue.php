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

use Common\Library\Jwt\Token;
use Common\Library\Jwt\Validation\Constraint;
use Common\Library\Jwt\Validation\ConstraintViolation;

final class HasClaimWithValue implements Constraint
{
    /**
     * @var
     */
    private $claim;

    /**
     * @var mixed
     */
    private $expectedValue;

    /**
     * @param string $claim
     * @param $expectedValue
     */
    public function __construct(string $claim, $expectedValue)
    {
        $this->claim = $claim;
        $this->expectedValue = $expectedValue;

        if (in_array($claim, Token\RegisteredClaims::ALL, true)) {
            throw CannotValidateARegisteredClaim::create($claim);
        }
    }

    /**
     * @param Token $token
     * @return void
     */
    public function assert(Token $token): void
    {
        $claims = $token->claims();

        if (! $claims->has($this->claim)) {
            throw ConstraintViolation::error('The token does not have the claim "' . $this->claim . '"', $this);
        }

        if ($claims->get($this->claim) != $this->expectedValue) {
            throw ConstraintViolation::error(
                'The claim "' . $this->claim . '" does not have the expected value',
                $this,
            );
        }
    }
}