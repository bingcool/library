<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\API\Metrics\UpDownCounterInterface;

/**
 * @internal
 */
final class UpDownCounter implements UpDownCounterInterface, InstrumentHandle
{
    use SynchronousInstrumentTrait { write as add; }
}
