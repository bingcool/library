<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\MetricExporter;

use Common\Library\OpenTelemetry\SDK\Metrics\MetricExporterFactoryInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\MetricExporterInterface;

class NoopMetricExporterFactory implements MetricExporterFactoryInterface
{
    #[\Override]
    public function create(): MetricExporterInterface
    {
        return new NoopMetricExporter();
    }
}
