<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs\Exporter;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Export\InMemoryStorageManager;
use Swoolefy\Library\OpenTelemetry\SDK\Logs\LogRecordExporterFactoryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Logs\LogRecordExporterInterface;

class InMemoryExporterFactory implements LogRecordExporterFactoryInterface
{
    #[\Override]
    public function create(): LogRecordExporterInterface
    {
        return new InMemoryExporter(InMemoryStorageManager::logs());
    }
}
