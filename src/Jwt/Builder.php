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

use DateTimeImmutable;
use Common\Library\Jwt\Encoding\CannotEncodeContent;
use Common\Library\Jwt\Signer\CannotSignPayload;
use Common\Library\Jwt\Signer\Ecdsa\ConversionFailed;
use Common\Library\Jwt\Signer\InvalidKeyProvided;
use Common\Library\Jwt\Signer\Key;
use Common\Library\Jwt\Token\Plain;
use Common\Library\Jwt\Token\RegisteredClaimGiven;

interface Builder
{
    /**
     * Appends new items to audience
     */
    public function permittedFor(string ...$audiences): Builder;

    /**
     * Configures the expiration time
     */
    public function expiresAt(DateTimeImmutable $expiration): Builder;

    /**
     * Configures the token id
     */
    public function identifiedBy(string $id): Builder;

    /**
     * Configures the time that the token was issued
     */
    public function issuedAt(DateTimeImmutable $issuedAt): Builder;

    /**
     * Configures the issuer
     */
    public function issuedBy(string $issuer): Builder;

    /**
     * Configures the time before which the token cannot be accepted
     */
    public function canOnlyBeUsedAfter(DateTimeImmutable $notBefore): Builder;

    /**
     * Configures the subject
     */
    public function relatedTo(string $subject): Builder;

    /**
     * Configures a header item
     *
     * @param mixed $value
     */
    public function withHeader(string $name, $value): Builder;

    /**
     * Configures a claim item
     *
     * @param mixed $value
     *
     * @throws RegisteredClaimGiven When trying to set a registered claim.
     */
    public function withClaim(string $name, $value): Builder;

    /**
     * Returns a signed token to be used
     *
     * @throws CannotEncodeContent When data cannot be converted to JSON.
     * @throws CannotSignPayload   When payload signing fails.
     * @throws InvalidKeyProvided  When issue key is invalid/incompatible.
     * @throws ConversionFailed    When signature could not be converted.
     */
    public function getToken(Signer $signer, Key $key): Plain;
}
