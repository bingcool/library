<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\MetricExporter;

use Common\Library\OpenTelemetry\SDK\Common\Export\InMemoryStorageManager;
use Common\Library\OpenTelemetry\SDK\Metrics\MetricExporterFactoryInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\MetricExporterInterface;

class InMemoryExporterFactory implements MetricExporterFactoryInterface
{
    #[\Override]
    public function create(): MetricExporterInterface
    {
        return new InMemoryExporter(InMemoryStorageManager::metrics());
    }
}
