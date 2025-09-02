<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\SDK\Metrics\Data\Metric;

interface MetricExporterInterface
{
    /**
     * @param iterable<int, Metric> $batch
     */
    public function export(iterable $batch): bool;

    public function shutdown(): bool;
}
