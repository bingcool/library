<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace;

use Swoolefy\Library\OpenTelemetry\API;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;

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
