<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\MetricExporter;

use Common\Library\OpenTelemetry\SDK\Metrics\MetricExporterInterface;

class NoopMetricExporter implements MetricExporterInterface
{
    /**
     * @inheritDoc
     */
    #[\Override]
    public function export(iterable $batch): bool
    {
        return true;
    }

    #[\Override]
    public function shutdown(): bool
    {
        return true;
    }
}
