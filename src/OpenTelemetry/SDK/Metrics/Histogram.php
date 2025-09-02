<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * @internal
 */
final class Histogram implements HistogramInterface, InstrumentHandle
{
    use SynchronousInstrumentTrait { write as record; }
}
