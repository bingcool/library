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

use Common\Library\Jwt\Encoding\CannotDecodeContent;

interface Decoder
{
    /**
     * Decodes from JSON, validating the errors
     *
     * @return mixed
     *
     * @throws CannotDecodeContent When something goes wrong while decoding.
     */
    public function jsonDecode(string $json);

    /**
     * Decodes from Base64URL
     *
     * @link http://tools.ietf.org/html/rfc4648#section-5
     *
     * @throws CannotDecodeContent When something goes wrong while decoding.
     */
    public function base64UrlDecode(string $data): string;
}
