<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\API\Metrics\ObservableCounterInterface;

/**
 * @internal
 */
final class ObservableCounter implements ObservableCounterInterface, InstrumentHandle
{
    use ObservableInstrumentTrait;
}
