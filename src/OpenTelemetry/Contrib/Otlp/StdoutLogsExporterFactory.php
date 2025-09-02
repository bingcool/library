<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\Contrib\Otlp;

use Common\Library\OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use Common\Library\OpenTelemetry\SDK\Logs\LogRecordExporterFactoryInterface;
use Common\Library\OpenTelemetry\SDK\Logs\LogRecordExporterInterface;

class StdoutLogsExporterFactory implements LogRecordExporterFactoryInterface
{
    public function create(): LogRecordExporterInterface
    {
        $transport = (new StreamTransportFactory())->create('php://stdout', ContentTypes::NDJSON);

        return new LogsExporter($transport);
    }
}
