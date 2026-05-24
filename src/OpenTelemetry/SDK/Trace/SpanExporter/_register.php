<?php

declare(strict_types=1);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('console', \Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory::class);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('memory', \Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporter\InMemorySpanExporterFactory::class);

\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerTransportFactory('stream', \Swoolefy\Library\OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory::class);
