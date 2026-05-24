<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace;

use Swoolefy\Library\OpenTelemetry\API\Trace as API;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\InstrumentationScope\Configurable;

interface TracerProviderInterface extends API\TracerProviderInterface, Configurable
{
    public function forceFlush(?CancellationInterface $cancellation = null): bool;

    public function shutdown(?CancellationInterface $cancellation = null): bool;
}
