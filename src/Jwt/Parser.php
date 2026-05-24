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

namespace Swoolefy\Library\Jwt;

use Swoolefy\Library\Jwt\Encoding\CannotDecodeContent;
use Swoolefy\Library\Jwt\Token\InvalidTokenStructure;
use Swoolefy\Library\Jwt\Token\UnsupportedHeaderFound;

interface Parser
{
    /**
     * Parses the JWT and returns a token
     *
     * @throws CannotDecodeContent      When something goes wrong while decoding.
     * @throws InvalidTokenStructure    When token string structure is invalid.
     * @throws UnsupportedHeaderFound   When parsed token has an unsupported header.
     */
    public function parse(string $jwtToken): Token;
}
