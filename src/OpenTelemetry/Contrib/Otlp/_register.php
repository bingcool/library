<?php

declare(strict_types=1);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('otlp', \Swoolefy\Library\OpenTelemetry\Contrib\Otlp\SpanExporterFactory::class);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('otlp/stdout', \Swoolefy\Library\OpenTelemetry\Contrib\Otlp\StdoutSpanExporterFactory::class);

\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('otlp', \Swoolefy\Library\OpenTelemetry\Contrib\Otlp\MetricExporterFactory::class);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerMetricExporterFactory('otlp/stdout', \Swoolefy\Library\OpenTelemetry\Contrib\Otlp\StdoutMetricExporterFactory::class);

\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerTransportFactory('http', \Swoolefy\Library\OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory::class);

\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerLogRecordExporterFactory('otlp', \Swoolefy\Library\OpenTelemetry\Contrib\Otlp\LogsExporterFactory::class);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerLogRecordExporterFactory('otlp/stdout', \Swoolefy\Library\OpenTelemetry\Contrib\Otlp\StdoutLogsExporterFactory::class);
