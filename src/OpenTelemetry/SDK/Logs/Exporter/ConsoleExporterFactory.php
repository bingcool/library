<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs\Exporter;

use Common\Library\OpenTelemetry\SDK\Logs\LogRecordExporterFactoryInterface;
use Common\Library\OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use Common\Library\OpenTelemetry\SDK\Registry;

class ConsoleExporterFactory implements LogRecordExporterFactoryInterface
{
    #[\Override]
    public function create(): LogRecordExporterInterface
    {
        $transport = Registry::transportFactory('stream')->create('php://stdout', 'application/json');

        return new ConsoleExporter($transport);
    }
}
