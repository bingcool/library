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

final class InvalidTokenStructure extends InvalidArgumentException implements Exception
{
    public static function missingOrNotEnoughSeparators(): self
    {
        return new self('The JWT string must have two dots');
    }

    public static function arrayExpected(string $part): self
    {
        return new self($part . ' must be an array');
    }

    public static function dateIsNotParseable(string $value): self
    {
        return new self('Value is not in the allowed date format: ' . $value);
    }
}
