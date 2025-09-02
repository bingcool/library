<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace\SpanExporter;

use Common\Library\OpenTelemetry\SDK\Registry;
use Common\Library\OpenTelemetry\SDK\Trace\SpanExporterInterface;

class ConsoleSpanExporterFactory implements SpanExporterFactoryInterface
{
    #[\Override]
    public function create(): SpanExporterInterface
    {
        $transport = Registry::transportFactory('stream')->create('php://stdout', 'application/json');

        return new ConsoleSpanExporter($transport);
    }
}
