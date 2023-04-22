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

use Common\Library\Jwt\Signer\Ecdsa;

final class Sha512 extends Ecdsa
{
    public function algorithmId(): string
    {
        return 'ES512';
    }

    public function algorithm(): int
    {
        return OPENSSL_ALGO_SHA512;
    }

    public function keyLength(): int
    {
        return 132;
    }
}
