<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

/**
 * @internal
 */
interface InstrumentHandle
{
    public function getHandle(): Instrument;
}
