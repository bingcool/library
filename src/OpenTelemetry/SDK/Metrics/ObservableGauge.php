<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\API\Metrics\ObservableGaugeInterface;

/**
 * @internal
 */
final class ObservableGauge implements ObservableGaugeInterface, InstrumentHandle
{
    use ObservableInstrumentTrait;
}
