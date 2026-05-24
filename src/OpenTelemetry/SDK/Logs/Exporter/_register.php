<?php

declare(strict_types=1);

\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerLogRecordExporterFactory('console', \Swoolefy\Library\OpenTelemetry\SDK\Logs\Exporter\ConsoleExporterFactory::class);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerLogRecordExporterFactory('memory', \Swoolefy\Library\OpenTelemetry\SDK\Logs\Exporter\InMemoryExporterFactory::class);
