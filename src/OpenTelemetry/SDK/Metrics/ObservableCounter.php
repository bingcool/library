<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\API\Metrics\ObservableCounterInterface;

/**
 * @internal
 */
final class ObservableCounter implements ObservableCounterInterface, InstrumentHandle
{
    use ObservableInstrumentTrait;
}
