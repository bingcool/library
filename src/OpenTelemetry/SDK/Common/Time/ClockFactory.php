<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Time;

use Swoolefy\Library\OpenTelemetry\API\Common\Time\Clock;
use Swoolefy\Library\OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * @deprecated use Swoolefy\Library\OpenTelemetry\API\Common\Time\Clock
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
