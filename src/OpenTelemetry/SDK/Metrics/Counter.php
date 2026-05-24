<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\API\Metrics\CounterInterface;

/**
 * @internal
 */
final class Counter implements CounterInterface, InstrumentHandle
{
    use SynchronousInstrumentTrait { write as add; }
}
