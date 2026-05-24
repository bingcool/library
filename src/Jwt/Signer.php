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

use Swoolefy\Library\Jwt\Signer\CannotSignPayload;
use Swoolefy\Library\Jwt\Signer\Ecdsa\ConversionFailed;
use Swoolefy\Library\Jwt\Signer\InvalidKeyProvided;
use Swoolefy\Library\Jwt\Signer\Key;

interface Signer
{
    /**
     * Returns the algorithm id
     */
    public function algorithmId(): string;

    /**
     * Creates a hash for the given payload
     *
     * @throws CannotSignPayload  When payload signing fails.
     * @throws InvalidKeyProvided When issue key is invalid/incompatible.
     * @throws ConversionFailed   When signature could not be converted.
     */
    public function sign(string $payload, Key $key): string;

    /**
     * Returns if the expected hash matches with the data and key
     *
     * @throws InvalidKeyProvided When issue key is invalid/incompatible.
     * @throws ConversionFailed   When signature could not be converted.
     */
    public function verify(string $expected, string $payload, Key $key): bool;
}
