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

namespace Common\Library\Jwt;

use Common\Library\Jwt\Encoding\CannotEncodeContent;

interface Encoder
{
    /**
     * Encodes to JSON, validating the errors
     *
     * @param mixed $data
     *
     * @throws CannotEncodeContent When something goes wrong while encoding.
     */
    public function jsonEncode($data): string;

    /**
     * Encodes to base64url
     *
     * @link http://tools.ietf.org/html/rfc4648#section-5
     */
    public function base64UrlEncode(string $data): string;
}
