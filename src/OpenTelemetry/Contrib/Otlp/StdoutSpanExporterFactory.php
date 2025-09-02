<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\Contrib\Otlp;

use Common\Library\OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use Common\Library\OpenTelemetry\SDK\Trace\SpanExporter\SpanExporterFactoryInterface;
use Common\Library\OpenTelemetry\SDK\Trace\SpanExporterInterface;

class StdoutSpanExporterFactory implements SpanExporterFactoryInterface
{
    public function create(): SpanExporterInterface
    {
        $transport = (new StreamTransportFactory())->create('php://stdout', ContentTypes::NDJSON);

        return new SpanExporter($transport);
    }
}
