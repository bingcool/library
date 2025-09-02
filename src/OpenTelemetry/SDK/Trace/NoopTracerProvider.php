<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace;

use Common\Library\OpenTelemetry\API;
use Common\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Common\Library\OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;

class NoopTracerProvider extends API\Trace\NoopTracerProvider implements TracerProviderInterface
{
    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    #[\Override]
    public function updateConfigurator(Configurator $configurator): void
    {
    }
}
