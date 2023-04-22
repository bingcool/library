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


namespace Common\Library\Jwt\Signer\Ecdsa;

use InvalidArgumentException;
use Common\Library\Jwt\Exception;

final class ConversionFailed extends InvalidArgumentException implements Exception
{
    public static function invalidLength(): self
    {
        return new self('Invalid signature length.');
    }

    public static function incorrectStartSequence(): self
    {
        return new self('Invalid data. Should start with a sequence.');
    }

    public static function integerExpected(): self
    {
        return new self('Invalid data. Should contain an integer.');
    }
}
