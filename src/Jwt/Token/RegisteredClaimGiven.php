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

use InvalidArgumentException;
use Common\Library\Jwt\Exception;

final class RegisteredClaimGiven extends InvalidArgumentException implements Exception
{
    private const DEFAULT_MESSAGE = 'Builder#withClaim() is meant to be used for non-registered claims, '
                                  . 'check the documentation on how to set claim "%s"';

    public static function forClaim(string $name): self
    {
        return new self(sprintf(self::DEFAULT_MESSAGE, $name));
    }
}
