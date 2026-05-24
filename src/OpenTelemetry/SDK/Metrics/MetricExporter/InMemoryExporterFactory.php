<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporter;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Export\InMemoryStorageManager;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporterFactoryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporterInterface;

class InMemoryExporterFactory implements MetricExporterFactoryInterface
{
    #[\Override]
    public function create(): MetricExporterInterface
    {
        return new InMemoryExporter(InMemoryStorageManager::metrics());
    }
}
