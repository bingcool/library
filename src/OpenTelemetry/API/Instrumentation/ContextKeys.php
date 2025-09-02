<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Instrumentation;

use Common\Library\OpenTelemetry\API\Logs\EventLoggerProviderInterface;
use Common\Library\OpenTelemetry\API\Logs\LoggerProviderInterface;
use Common\Library\OpenTelemetry\API\Metrics\MeterProviderInterface;
use Common\Library\OpenTelemetry\API\Trace\TracerProviderInterface;
use Common\Library\OpenTelemetry\Context\Context;
use Common\Library\OpenTelemetry\Context\ContextKeyInterface;
use Common\Library\OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * @internal
 */
final class ContextKeys
{
    /**
     * @return ContextKeyInterface<TracerProviderInterface>
     */
    public static function tracerProvider(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey(TracerProviderInterface::class);
    }

    /**
     * @return ContextKeyInterface<MeterProviderInterface>
     */
    public static function meterProvider(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey(MeterProviderInterface::class);
    }

    /**
     * @return ContextKeyInterface<TextMapPropagatorInterface>
     */
    public static function propagator(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey(TextMapPropagatorInterface::class);
    }

    /**
     * @return ContextKeyInterface<LoggerProviderInterface>
     */
    public static function loggerProvider(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey(LoggerProviderInterface::class);
    }

    /**
     * @deprecated
     * @return ContextKeyInterface<EventLoggerProviderInterface>
     */
    public static function eventLoggerProvider(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey(EventLoggerProviderInterface::class);
    }
}
