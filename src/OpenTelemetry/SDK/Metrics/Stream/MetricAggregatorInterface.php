<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Stream;

/**
 * @internal
 */
interface MetricAggregatorInterface extends WritableMetricStreamInterface, MetricCollectorInterface
{
}
