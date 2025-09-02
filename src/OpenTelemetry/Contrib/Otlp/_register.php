<?php

declare(strict_types=1);
\use Common\Library\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('otlp', \OpenTelemetry\Contrib\Otlp\SpanExporterFactory::class);
\use Common\Library\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('otlp/stdout', \OpenTelemetry\Contrib\Otlp\StdoutSpanExporterFactory::class);

\use Common\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('otlp', \OpenTelemetry\Contrib\Otlp\MetricExporterFactory::class);
\use Common\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('otlp/stdout', \OpenTelemetry\Contrib\Otlp\StdoutMetricExporterFactory::class);

\use Common\Library\OpenTelemetry\SDK\Registry::registerTransportFactory('http', \OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory::class);

\use Common\Library\OpenTelemetry\SDK\Registry::registerLogRecordExporterFactory('otlp', \OpenTelemetry\Contrib\Otlp\LogsExporterFactory::class);
\use Common\Library\OpenTelemetry\SDK\Registry::registerLogRecordExporterFactory('otlp/stdout', \OpenTelemetry\Contrib\Otlp\StdoutLogsExporterFactory::class);
