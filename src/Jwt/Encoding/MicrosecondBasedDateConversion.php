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

use DateTimeImmutable;
use Common\Library\Jwt\ClaimsFormatter;
use Common\Library\Jwt\Token\RegisteredClaims;

final class MicrosecondBasedDateConversion implements ClaimsFormatter
{
    /**
     * @param array $claims
     * @return array|mixed[]
     */
    public function formatClaims(array $claims): array
    {
        foreach (RegisteredClaims::DATE_CLAIMS as $claim) {
            if (! array_key_exists($claim, $claims)) {
                continue;
            }

            $claims[$claim] = $this->convertDate($claims[$claim]);
        }

        return $claims;
    }

    /**
     * @param DateTimeImmutable $date
     * @return float|int
     */
    private function convertDate(DateTimeImmutable $date)
    {
        if ($date->format('u') === '000000') {
            return (int) $date->format('U');
        }

        return (float) $date->format('U.u');
    }
}
