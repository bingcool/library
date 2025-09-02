<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\API\Metrics\GaugeInterface;

/**
 * @internal
 */
final class Gauge implements GaugeInterface, InstrumentHandle
{
    use SynchronousInstrumentTrait { write as record; }
}
