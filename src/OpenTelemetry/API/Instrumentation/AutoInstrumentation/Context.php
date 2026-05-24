<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Instrumentation\AutoInstrumentation;

use Swoolefy\Library\OpenTelemetry\API\Logs\LoggerProviderInterface;
use Swoolefy\Library\OpenTelemetry\API\Logs\NoopLoggerProvider;
use Swoolefy\Library\OpenTelemetry\API\Metrics\MeterProviderInterface;
use Swoolefy\Library\OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use Swoolefy\Library\OpenTelemetry\API\Trace\NoopTracerProvider;
use Swoolefy\Library\OpenTelemetry\API\Trace\TracerProviderInterface;
use Swoolefy\Library\OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use Swoolefy\Library\OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * Context used for component creation.
 */
final class Context
{
    public function __construct(
        public readonly TracerProviderInterface $tracerProvider = new NoopTracerProvider(),
        public readonly MeterProviderInterface $meterProvider = new NoopMeterProvider(),
        public readonly LoggerProviderInterface $loggerProvider = new NoopLoggerProvider(),
        public readonly TextMapPropagatorInterface $propagator = new NoopTextMapPropagator(),
    ) {
    }
}
