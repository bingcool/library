<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\Contrib\Otlp;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporter\SpanExporterFactoryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporterInterface;

class StdoutSpanExporterFactory implements SpanExporterFactoryInterface
{
    public function create(): SpanExporterInterface
    {
        $transport = (new StreamTransportFactory())->create('php://stdout', ContentTypes::NDJSON);

        return new SpanExporter($transport);
    }
}
