<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Common\Time;

use Common\Library\OpenTelemetry\API\Common\Time\Clock;
use Common\Library\OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * @deprecated use Common\Library\OpenTelemetry\API\Common\Time\Clock
 * @codeCoverageIgnore
 */
class ClockFactory
{
    public static function getDefault(): ClockInterface
    {
        return Clock::getDefault();
    }

    public static function setDefault(?ClockInterface $clock): void
    {
        if ($clock !== null) {
            Clock::setDefault($clock);
        } else {
            Clock::reset();
        }
    }
}
