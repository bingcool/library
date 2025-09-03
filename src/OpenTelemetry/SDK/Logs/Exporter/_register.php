<?php

declare(strict_types=1);

\Common\Library\OpenTelemetry\SDK\Registry::registerLogRecordExporterFactory('console', \Common\Library\OpenTelemetry\SDK\Logs\Exporter\ConsoleExporterFactory::class);
\Common\Library\OpenTelemetry\SDK\Registry::registerLogRecordExporterFactory('memory', \Common\Library\OpenTelemetry\SDK\Logs\Exporter\InMemoryExporterFactory::class);
