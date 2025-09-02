<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;

/**
 * @internal
 */
final class ObservableUpDownCounter implements ObservableUpDownCounterInterface, InstrumentHandle
{
    use ObservableInstrumentTrait;
}
