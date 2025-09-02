<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\API\Metrics\ObservableGaugeInterface;

/**
 * @internal
 */
final class ObservableGauge implements ObservableGaugeInterface, InstrumentHandle
{
    use ObservableInstrumentTrait;
}
