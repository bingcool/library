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

namespace Common\Library\Jwt\Encoding;

use JsonException;
use Common\Library\Jwt\Exception;
use RuntimeException;

final class CannotDecodeContent extends RuntimeException implements Exception
{
    public static function jsonIssues(JsonException $previous): self
    {
        return new self('Error while decoding from JSON', 0, $previous);
    }

    public static function invalidBase64String(): self
    {
        return new self('Error while decoding from Base64Url, invalid base64 characters detected');
    }
}
