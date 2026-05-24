<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporter;

use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporterFactoryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporterInterface;

class NoopMetricExporterFactory implements MetricExporterFactoryInterface
{
    #[\Override]
    public function create(): MetricExporterInterface
    {
        return new NoopMetricExporter();
    }
}
