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

use Closure;
use Common\Library\Jwt\Encoding\ChainedFormatter;
use Common\Library\Jwt\Encoding\JoseEncoder;
use Common\Library\Jwt\Signer\Key;
use Common\Library\Jwt\Signer\Key\InMemory;
use Common\Library\Jwt\Signer\None;
use Common\Library\Jwt\Validation\Constraint;

final class Configuration
{
    /**
     * @var Parser|Token\Parser
     */
    private $parser;

    /**
     * @var Signer
     */
    private $signer;

    /**
     * @var Key
     */
    private $signingKey;

    /**
     * @var Key
     */
    private $verificationKey;

    /**
     * @var Validator|Validation\Validator
     */
    private $validator;

    /**
     * @var Closure(ClaimsFormatter $claimFormatter): Builder
     */
    private $builderFactory;

    /**
     * @var Constraint[]
     */
    private $validationConstraints = [];

    /**
     * @param Signer $signer
     * @param Key $signingKey
     * @param Key $verificationKey
     * @param Encoder|null $encoder
     * @param Decoder|null $decoder
     */
    private function __construct(
        Signer $signer,
        Key $signingKey,
        Key $verificationKey,
        ?Encoder $encoder = null,
        ?Decoder $decoder = null
    ) {
        $this->signer          = $signer;
        $this->signingKey      = $signingKey;
        $this->verificationKey = $verificationKey;
        $this->parser          = new Token\Parser($decoder ?? new JoseEncoder());
        $this->validator       = new Validation\Validator();

        $this->builderFactory = static function (ClaimsFormatter $claimFormatter) use ($encoder): Builder {
            return new Token\Builder($encoder ?? new JoseEncoder(), $claimFormatter);
        };
    }

    public static function forAsymmetricSigner(
        Signer $signer,
        Key $signingKey,
        Key $verificationKey,
        ?Encoder $encoder = null,
        ?Decoder $decoder = null
    ): self {
        return new self(
            $signer,
            $signingKey,
            $verificationKey,
            $encoder,
            $decoder
        );
    }

    public static function forSymmetricSigner(
        Signer $signer,
        Key $key,
        ?Encoder $encoder = null,
        ?Decoder $decoder = null
    ): self {
        return new self(
            $signer,
            $key,
            $key,
            $encoder,
            $decoder
        );
    }

    public static function forUnsecuredSigner(
        ?Encoder $encoder = null,
        ?Decoder $decoder = null
    ): self {
        $key = InMemory::empty();

        return new self(
            new None(),
            $key,
            $key,
            $encoder,
            $decoder
        );
    }

    /**
     * @param callable(ClaimsFormatter): Builder $builderFactory
     */
    public function setBuilderFactory(callable $builderFactory): void
    {
        $this->builderFactory = Closure::fromCallable($builderFactory);
    }

    public function builder(?ClaimsFormatter $claimFormatter = null): Builder
    {
        return ($this->builderFactory)($claimFormatter ?? ChainedFormatter::default());
    }

    public function parser(): Parser
    {
        return $this->parser;
    }

    public function setParser(Parser $parser): void
    {
        $this->parser = $parser;
    }

    public function signer(): Signer
    {
        return $this->signer;
    }

    public function signingKey(): Key
    {
        return $this->signingKey;
    }

    public function verificationKey(): Key
    {
        return $this->verificationKey;
    }

    public function validator(): Validator
    {
        return $this->validator;
    }

    public function setValidator(Validator $validator): void
    {
        $this->validator = $validator;
    }

    /**
     * @return Constraint[]
     */
    public function validationConstraints(): array
    {
        return $this->validationConstraints;
    }

    public function setValidationConstraints(Constraint ...$validationConstraints): void
    {
        $this->validationConstraints = $validationConstraints;
    }
}
