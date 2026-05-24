<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Data\Metric;

interface MetricExporterInterface
{
    /**
     * @param iterable<int, Metric> $batch
     */
    public function export(iterable $batch): bool;

    public function shutdown(): bool;
}
