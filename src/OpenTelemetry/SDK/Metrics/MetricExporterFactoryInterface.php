<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

interface MetricExporterFactoryInterface
{
    public function create(): MetricExporterInterface;
}
