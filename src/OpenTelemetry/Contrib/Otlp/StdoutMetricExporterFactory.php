<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\Contrib\Otlp;

use Common\Library\OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use Common\Library\OpenTelemetry\SDK\Metrics\MetricExporterFactoryInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\MetricExporterInterface;

class StdoutMetricExporterFactory implements MetricExporterFactoryInterface
{
    public function create(): MetricExporterInterface
    {
        $transport = (new StreamTransportFactory())->create('php://stdout', ContentTypes::NDJSON);

        return new MetricExporter($transport);
    }
}
