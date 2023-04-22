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

final class RequiredConstraintsViolated extends RuntimeException implements Exception
{
    /**
     * @var array
     */
    private $violations = [];

    public static function fromViolations(ConstraintViolation ...$violations): self
    {
        $exception             = new self(self::buildMessage($violations));
        $exception->violations = $violations;

        return $exception;
    }

    /** @param ConstraintViolation[] $violations */
    private static function buildMessage(array $violations): string
    {
        $violations = array_map(
            static function (ConstraintViolation $violation): string {
                return '- ' . $violation->getMessage();
            },
            $violations
        );

        $message  = "The token violates some mandatory constraints, details:\n";
        $message .= implode("\n", $violations);

        return $message;
    }

    /** @return ConstraintViolation[] */
    public function violations(): array
    {
        return $this->violations;
    }
}
