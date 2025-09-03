<?php

declare(strict_types=1);
\Common\Library\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('otlp', \Common\Library\OpenTelemetry\Contrib\Otlp\SpanExporterFactory::class);
\Common\Library\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('otlp/stdout', \Common\Library\OpenTelemetry\Contrib\Otlp\StdoutSpanExporterFactory::class);

\Common\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('otlp', \Common\Library\OpenTelemetry\Contrib\Otlp\MetricExporterFactory::class);
\Common\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('otlp/stdout', \Common\Library\OpenTelemetry\Contrib\Otlp\StdoutMetricExporterFactory::class);

\Common\Library\OpenTelemetry\SDK\Registry::registerTransportFactory('http', \Common\Library\OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory::class);

\Common\Library\OpenTelemetry\SDK\Registry::registerLogRecordExporterFactory('otlp', \Common\Library\OpenTelemetry\Contrib\Otlp\LogsExporterFactory::class);
\Common\Library\OpenTelemetry\SDK\Registry::registerLogRecordExporterFactory('otlp/stdout', \Common\Library\OpenTelemetry\Contrib\Otlp\StdoutLogsExporterFactory::class);
