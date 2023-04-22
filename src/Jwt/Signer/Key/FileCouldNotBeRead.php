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


namespace Common\Library\Jwt\Signer\Key;

use InvalidArgumentException;
use Common\Library\Jwt\Exception;
use Throwable;

final class FileCouldNotBeRead extends InvalidArgumentException implements Exception
{
    public static function onPath(string $path, ?Throwable $cause = null): self
    {
        return new self(
            'The path "' . $path . '" does not contain a valid key file',
            0,
            $cause
        );
    }
}
