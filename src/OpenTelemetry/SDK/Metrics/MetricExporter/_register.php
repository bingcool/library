<?php

declare(strict_types=1);

\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('memory', \Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporterFactory::class);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('console', \Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporterFactory::class);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('none', \Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricExporter\NoopMetricExporterFactory::class);
