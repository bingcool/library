<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace\SpanExporter;

use Common\Library\OpenTelemetry\SDK\Common\Export\InMemoryStorageManager;
use Common\Library\OpenTelemetry\SDK\Trace\SpanExporterInterface;

class InMemorySpanExporterFactory implements SpanExporterFactoryInterface
{
    #[\Override]
    public function create(): SpanExporterInterface
    {
        return new InMemoryExporter(InMemoryStorageManager::spans());
    }
}
