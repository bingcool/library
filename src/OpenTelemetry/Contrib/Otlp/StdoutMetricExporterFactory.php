<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\Contrib\Otlp;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporterFactoryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporterInterface;

class StdoutMetricExporterFactory implements MetricExporterFactoryInterface
{
    public function create(): MetricExporterInterface
    {
        $transport = (new StreamTransportFactory())->create('php://stdout', ContentTypes::NDJSON);

        return new MetricExporter($transport);
    }
}
