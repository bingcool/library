<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Stream;

/**
 * @internal
 */
interface MetricCollectorInterface
{
    public function collect(int $timestamp): Metric;
}
