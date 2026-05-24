<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;

/**
 * @internal
 */
final class ObservableUpDownCounter implements ObservableUpDownCounterInterface, InstrumentHandle
{
    use ObservableInstrumentTrait;
}
