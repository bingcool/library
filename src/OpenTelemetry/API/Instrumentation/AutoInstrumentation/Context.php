<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Instrumentation\AutoInstrumentation;

use Common\Library\OpenTelemetry\API\Logs\LoggerProviderInterface;
use Common\Library\OpenTelemetry\API\Logs\NoopLoggerProvider;
use Common\Library\OpenTelemetry\API\Metrics\MeterProviderInterface;
use Common\Library\OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use Common\Library\OpenTelemetry\API\Trace\NoopTracerProvider;
use Common\Library\OpenTelemetry\API\Trace\TracerProviderInterface;
use Common\Library\OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use Common\Library\OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

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
