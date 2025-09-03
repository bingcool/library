<?php

declare(strict_types=1);
\Common\Library\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('console', \Common\Library\OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory::class);
\Common\Library\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('memory', \Common\Library\OpenTelemetry\SDK\Trace\SpanExporter\InMemorySpanExporterFactory::class);

\Common\Library\OpenTelemetry\SDK\Registry::registerTransportFactory('stream', \Common\Library\OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory::class);
