<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace\SpanExporter;

use Common\Library\OpenTelemetry\SDK\Trace\SpanExporterInterface;

interface SpanExporterFactoryInterface
{
    public function create(): SpanExporterInterface;
}
