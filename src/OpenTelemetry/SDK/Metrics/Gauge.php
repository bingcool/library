<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\API\Metrics\GaugeInterface;

/**
 * @internal
 */
final class Gauge implements GaugeInterface, InstrumentHandle
{
    use SynchronousInstrumentTrait { write as record; }
}
