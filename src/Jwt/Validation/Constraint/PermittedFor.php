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

final class PermittedFor implements Constraint
{
    /**
     * @var string
     */
    private $audience;

    /**
     * @param string $audience
     */
    public function __construct(string $audience)
    {
        $this->audience = $audience;
    }

    public function assert(Token $token): void
    {
        if (! $token->isPermittedFor($this->audience)) {
            throw new ConstraintViolation(
                'The token is not allowed to be used by this audience'
            );
        }
    }
}
