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


namespace Common\Library\Jwt\Validation;

use Common\Library\Jwt\Token;

final class Validator implements \Common\Library\Jwt\Validator
{
    /**
     * @var array
     */
    protected $errorMsg = [];

    /**
     * @param Token $token
     * @param Constraint ...$constraints
     * @return void
     */
    public function assert(Token $token, Constraint ...$constraints): void
    {
        if ($constraints === []) {
            throw new NoConstraintsGiven('No constraint given.');
        }

        $violations = [];

        foreach ($constraints as $constraint) {
            $this->checkConstraint($constraint, $token, $violations);
        }

        if ($violations) {
            throw RequiredConstraintsViolated::fromViolations(...$violations);
        }
    }

    /**
     * @param Constraint $constraint
     * @param Token $token
     * @param array $violations
     * @return void
     */
    private function checkConstraint(
        Constraint $constraint,
        Token $token,
        array &$violations
    ): void {
        try {
            $constraint->assert($token);
        } catch (ConstraintViolation $e) {
            $violations[] = $e;
        }
    }

    public function validate(Token $token, Constraint ...$constraints): bool
    {
        if ($constraints === []) {
            throw new NoConstraintsGiven('No constraint given.');
        }

        try {
            foreach ($constraints as $constraint) {
                $constraint->assert($token);
            }
            return true;
        } catch (ConstraintViolation|\Exception|\Throwable $e) {
            $this->errorMsg[get_class($constraint)] = sprintf("Error: %s on %s in %d, trace=%s",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
            return false;
        }
    }

    /**
     * @return array
     */
    public function getErrorMsg()
    {
        return $this->errorMsg;
    }
}
