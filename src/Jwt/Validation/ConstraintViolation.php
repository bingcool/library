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

use Common\Library\Jwt\Exception;
use RuntimeException;

final class ConstraintViolation extends RuntimeException implements Exception
{
    /**
     * @var string|null
     */
    protected $constraint;

    /**
     * @param string $message
     * @param string|null $constraint
     */
    public function __construct(
        string $message = '',
        ?string $constraint = null
    ) {
        $this->constraint = $constraint;
        parent::__construct($message);
    }

    /**
     * @param string $message
     * @param Constraint $constraint
     * @return static
     */
    public static function error(string $message, Constraint $constraint): self
    {
        return new self($message, get_class($constraint));
    }
}
