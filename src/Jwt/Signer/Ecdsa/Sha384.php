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

final class Sha384 extends Ecdsa
{
    public function algorithmId(): string
    {
        return 'ES384';
    }

    public function algorithm(): int
    {
        return OPENSSL_ALGO_SHA384;
    }

    public function keyLength(): int
    {
        return 96;
    }
}
