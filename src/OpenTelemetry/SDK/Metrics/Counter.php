<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\API\Metrics\CounterInterface;

/**
 * @internal
 */
final class Counter implements CounterInterface, InstrumentHandle
{
    use SynchronousInstrumentTrait { write as add; }
}
