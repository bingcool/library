<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporter;

use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporterInterface;

interface SpanExporterFactoryInterface
{
    public function create(): SpanExporterInterface;
}
