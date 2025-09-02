<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\API\Metrics\UpDownCounterInterface;

/**
 * @internal
 */
final class UpDownCounter implements UpDownCounterInterface, InstrumentHandle
{
    use SynchronousInstrumentTrait { write as add; }
}
