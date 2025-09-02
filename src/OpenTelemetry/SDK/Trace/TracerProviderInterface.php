<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace;

use Common\Library\OpenTelemetry\API\Trace as API;
use Common\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Common\Library\OpenTelemetry\SDK\Common\InstrumentationScope\Configurable;

interface TracerProviderInterface extends API\TracerProviderInterface, Configurable
{
    public function forceFlush(?CancellationInterface $cancellation = null): bool;

    public function shutdown(?CancellationInterface $cancellation = null): bool;
}
