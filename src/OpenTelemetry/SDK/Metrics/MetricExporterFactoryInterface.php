<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

interface MetricExporterFactoryInterface
{
    public function create(): MetricExporterInterface;
}
