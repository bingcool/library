<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Instrumentation;

use Swoolefy\Library\OpenTelemetry\API\Logs\EventLoggerProviderInterface;
use Swoolefy\Library\OpenTelemetry\API\Logs\LoggerProviderInterface;
use Swoolefy\Library\OpenTelemetry\API\Logs\NoopEventLoggerProvider;
use Swoolefy\Library\OpenTelemetry\API\Logs\NoopLoggerProvider;
use Swoolefy\Library\OpenTelemetry\API\Metrics\MeterProviderInterface;
use Swoolefy\Library\OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use Swoolefy\Library\OpenTelemetry\API\Trace\NoopTracerProvider;
use Swoolefy\Library\OpenTelemetry\API\Trace\TracerProviderInterface;
use Swoolefy\Library\OpenTelemetry\Context\Context;
use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\Context\ImplicitContextKeyedInterface;
use Swoolefy\Library\OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use Swoolefy\Library\OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Swoolefy\Library\OpenTelemetry\Context\ScopeInterface;

/**
 * Configures the global (context scoped) instrumentation instances.
 *
 * @see Configurator::activate()
 */
final class Configurator implements ImplicitContextKeyedInterface
{
    private ?TracerProviderInterface $tracerProvider = null;
    private ?MeterProviderInterface $meterProvider = null;
    private ?TextMapPropagatorInterface $propagator = null;
    private ?LoggerProviderInterface $loggerProvider = null;
    private ?EventLoggerProviderInterface $eventLoggerProvider = null;

    private function __construct()
    {
    }

    /**
     * Creates a configurator that uses parent instances for not configured values.
     */
    public static function create(): Configurator
    {
        return new self();
    }

    /**
     * Creates a configurator that uses noop instances for not configured values.
     * @phan-suppress PhanDeprecatedFunction
     */
    public static function createNoop(): Configurator
    {
        return self::create()
            ->withTracerProvider(new NoopTracerProvider())
            ->withMeterProvider(new NoopMeterProvider())
            ->withPropagator(new NoopTextMapPropagator())
            ->withLoggerProvider(NoopLoggerProvider::getInstance())
            ->withEventLoggerProvider(new NoopEventLoggerProvider())
        ;
    }

    #[\Override]
    public function activate(): ScopeInterface
    {
        return $this->storeInContext()->activate();
    }

    /**
     * @phan-suppress PhanDeprecatedFunction
     */
    #[\Override]
    public function storeInContext(?ContextInterface $context = null): ContextInterface
    {
        $context ??= Context::getCurrent();

        if ($this->tracerProvider !== null) {
            $context = $context->with(ContextKeys::tracerProvider(), $this->tracerProvider);
        }
        if ($this->meterProvider !== null) {
            $context = $context->with(ContextKeys::meterProvider(), $this->meterProvider);
        }
        if ($this->propagator !== null) {
            $context = $context->with(ContextKeys::propagator(), $this->propagator);
        }
        if ($this->loggerProvider !== null) {
            $context = $context->with(ContextKeys::loggerProvider(), $this->loggerProvider);
        }
        if ($this->eventLoggerProvider !== null) {
            $context = $context->with(ContextKeys::eventLoggerProvider(), $this->eventLoggerProvider);
        }

        return $context;
    }

    public function withTracerProvider(?TracerProviderInterface $tracerProvider): Configurator
    {
        $self = clone $this;
        $self->tracerProvider = $tracerProvider;

        return $self;
    }

    public function withMeterProvider(?MeterProviderInterface $meterProvider): Configurator
    {
        $self = clone $this;
        $self->meterProvider = $meterProvider;

        return $self;
    }

    public function withPropagator(?TextMapPropagatorInterface $propagator): Configurator
    {
        $self = clone $this;
        $self->propagator = $propagator;

        return $self;
    }

    public function withLoggerProvider(?LoggerProviderInterface $loggerProvider): Configurator
    {
        $self = clone $this;
        $self->loggerProvider = $loggerProvider;

        return $self;
    }

    /**
     * @deprecated
     */
    public function withEventLoggerProvider(?EventLoggerProviderInterface $eventLoggerProvider): Configurator
    {
        $self = clone $this;
        $self->eventLoggerProvider = $eventLoggerProvider;

        return $self;
    }
}
