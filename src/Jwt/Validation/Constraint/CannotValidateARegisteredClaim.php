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

use InvalidArgumentException;
use Common\Library\Jwt\Exception;

final class CannotValidateARegisteredClaim extends InvalidArgumentException implements Exception
{
    /**
     * @param string $claim
     * @return static
     */
    public static function create(string $claim): self
    {
        return new self(
            'The claim "' . $claim . '" is a registered claim, another constraint must be used to validate its value',
        );
    }
}
