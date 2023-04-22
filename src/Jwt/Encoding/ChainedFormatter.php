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

use Common\Library\Jwt\ClaimsFormatter;

final class ChainedFormatter implements ClaimsFormatter
{
    /**
     * @var ClaimsFormatter[]
     */
    private $formatters;

    /**
     * @param ClaimsFormatter ...$formatters
     */
    public function __construct(ClaimsFormatter ...$formatters)
    {
        $this->formatters = $formatters;
    }

    /**
     * @return static
     */
    public static function default(): self
    {
        return new self(new UnifyAudience(), new MicrosecondBasedDateConversion());
    }

    /** @inheritdoc */
    public function formatClaims(array $claims): array
    {
        foreach ($this->formatters as $formatter) {
            $claims = $formatter->formatClaims($claims);
        }

        return $claims;
    }
}
