<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporter;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Export\InMemoryStorageManager;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporterInterface;

class InMemorySpanExporterFactory implements SpanExporterFactoryInterface
{
    #[\Override]
    public function create(): SpanExporterInterface
    {
        return new InMemoryExporter(InMemoryStorageManager::spans());
    }
}
