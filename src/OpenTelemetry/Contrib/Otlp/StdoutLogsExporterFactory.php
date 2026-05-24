<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\Contrib\Otlp;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use Swoolefy\Library\OpenTelemetry\SDK\Logs\LogRecordExporterFactoryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Logs\LogRecordExporterInterface;

class StdoutLogsExporterFactory implements LogRecordExporterFactoryInterface
{
    public function create(): LogRecordExporterInterface
    {
        $transport = (new StreamTransportFactory())->create('php://stdout', ContentTypes::NDJSON);

        return new LogsExporter($transport);
    }
}
