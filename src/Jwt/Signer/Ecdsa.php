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


namespace Common\Library\Jwt\Signer;

use Common\Library\Jwt\Signer\Ecdsa\MultibyteStringConverter;
use Common\Library\Jwt\Signer\Ecdsa\SignatureConverter;

abstract class Ecdsa extends OpenSSL
{
    /**
     * @var SignatureConverter
     */
    private $converter;

    /**
     * @param SignatureConverter $converter
     */
    public function __construct(SignatureConverter $converter)
    {
        $this->converter = $converter;
    }

    /**
     * @return Ecdsa
     */
    public static function create(): Ecdsa
    {
        return new static(new MultibyteStringConverter());  // @phpstan-ignore-line
    }

    /**
     * @param string $payload
     * @param Key $key
     * @return string
     */
    final public function sign(string $payload, Key $key): string
    {
        return $this->converter->fromAsn1(
            $this->createSignature($key->contents(), $key->passphrase(), $payload),
            $this->keyLength()
        );
    }

    /**
     * @param string $expected
     * @param string $payload
     * @param Key $key
     * @return bool
     */
    final public function verify(string $expected, string $payload, Key $key): bool
    {
        return $this->verifySignature(
            $this->converter->toAsn1($expected, $this->keyLength()),
            $payload,
            $key->contents()
        );
    }

    /**
     * @return int
     */
    final public function keyType(): int
    {
        return OPENSSL_KEYTYPE_EC;
    }

    /**
     * Returns the length of each point in the signature, so that we can calculate and verify R and S points properly
     *
     * @internal
     */
    abstract public function keyLength(): int;
}
