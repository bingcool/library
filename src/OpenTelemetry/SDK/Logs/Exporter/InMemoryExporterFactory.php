<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs\Exporter;

use Common\Library\OpenTelemetry\SDK\Common\Export\InMemoryStorageManager;
use Common\Library\OpenTelemetry\SDK\Logs\LogRecordExporterFactoryInterface;
use Common\Library\OpenTelemetry\SDK\Logs\LogRecordExporterInterface;

class InMemoryExporterFactory implements LogRecordExporterFactoryInterface
{
    #[\Override]
    public function create(): LogRecordExporterInterface
    {
        return new InMemoryExporter(InMemoryStorageManager::logs());
    }
}
