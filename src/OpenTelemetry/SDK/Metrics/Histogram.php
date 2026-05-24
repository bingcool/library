<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * @internal
 */
final class Histogram implements HistogramInterface, InstrumentHandle
{
    use SynchronousInstrumentTrait { write as record; }
}
