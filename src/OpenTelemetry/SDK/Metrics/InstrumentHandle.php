<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

/**
 * @internal
 */
interface InstrumentHandle
{
    public function getHandle(): Instrument;
}
