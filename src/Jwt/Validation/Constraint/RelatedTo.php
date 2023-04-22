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

final class RelatedTo implements Constraint
{
    /**
     * @var string
     */
    private $subject;

    public function __construct(string $subject)
    {
        $this->subject = $subject;
    }

    public function assert(Token $token): void
    {
        if (! $token->isRelatedTo($this->subject)) {
            throw new ConstraintViolation(
                'The token is not related to the expected subject'
            );
        }
    }
}
