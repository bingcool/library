<?php

declare(strict_types=1);

\Common\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('memory', \Common\Library\OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporterFactory::class);
\Common\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('console', \Common\Library\OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporterFactory::class);
\Common\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('none', \Common\Library\OpenTelemetry\SDK\Metrics\MetricExporter\NoopMetricExporterFactory::class);
