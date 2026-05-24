<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API;

use function assert;
use Closure;
use Swoolefy\Library\OpenTelemetry\API\Behavior\LogsMessagesTrait;
use Swoolefy\Library\OpenTelemetry\API\Instrumentation\Configurator;
use Swoolefy\Library\OpenTelemetry\API\Instrumentation\ContextKeys;
use Swoolefy\Library\OpenTelemetry\API\Logs\EventLoggerProviderInterface;
use Swoolefy\Library\OpenTelemetry\API\Logs\LoggerProviderInterface;
use Swoolefy\Library\OpenTelemetry\API\Metrics\MeterProviderInterface;
use Swoolefy\Library\OpenTelemetry\API\Trace\TracerProviderInterface;
use Swoolefy\Library\OpenTelemetry\Context\Context;
use Swoolefy\Library\OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use function sprintf;
use Throwable;

/**
 * Provides access to the globally configured instrumentation instances.
 */
final class Globals
{
    use LogsMessagesTrait;

    /** @var Closure[] */
    private static array $initializers = [];
    private static ?self $globals = null;

    public function __construct(
        private readonly TracerProviderInterface $tracerProvider,
        private readonly MeterProviderInterface $meterProvider,
        private readonly LoggerProviderInterface $loggerProvider,
        private readonly EventLoggerProviderInterface $eventLoggerProvider,
        private readonly TextMapPropagatorInterface $propagator,
    ) {
    }

    public static function tracerProvider(): TracerProviderInterface
    {
        return Context::getCurrent()->get(ContextKeys::tracerProvider()) ?? self::globals()->tracerProvider;
    }

    public static function meterProvider(): MeterProviderInterface
    {
        return Context::getCurrent()->get(ContextKeys::meterProvider()) ?? self::globals()->meterProvider;
    }

    public static function propagator(): TextMapPropagatorInterface
    {
        return Context::getCurrent()->get(ContextKeys::propagator()) ?? self::globals()->propagator;
    }

    public static function loggerProvider(): LoggerProviderInterface
    {
        return Context::getCurrent()->get(ContextKeys::loggerProvider()) ?? self::globals()->loggerProvider;
    }

    /**
     * @deprecated
     * @phan-suppress PhanDeprecatedFunction
     */
    public static function eventLoggerProvider(): EventLoggerProviderInterface
    {
        return Context::getCurrent()->get(ContextKeys::eventLoggerProvider()) ?? self::globals()->eventLoggerProvider;
    }

    /**
     * @param Closure(Configurator): Configurator $initializer
     *
     * @internal
     * @psalm-internal OpenTelemetry
     * @todo In a future (breaking) change, we can remove `Registry` and globals initializers, in favor of SPI.
     */
    public static function registerInitializer(Closure $initializer): void
    {
        self::$initializers[] = $initializer;
    }

    /**
     * @phan-suppress PhanTypeMismatchReturnNullable,PhanDeprecatedFunction
     */
    private static function globals(): self
    {
        if (self::$globals !== null) {
            return self::$globals;
        }

        $configurator = Configurator::createNoop();
        $scope = $configurator->activate();

        try {
            foreach (self::$initializers as $initializer) {
                try {
                    $configurator = $initializer($configurator);
                } catch (Throwable $e) {
                    self::logWarning(sprintf("Error during opentelemetry initialization: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
                }
            }
        } finally {
            $scope->detach();
        }

        $context = $configurator->storeInContext();
        $tracerProvider = $context->get(ContextKeys::tracerProvider());
        $meterProvider = $context->get(ContextKeys::meterProvider());
        $propagator = $context->get(ContextKeys::propagator());
        $loggerProvider = $context->get(ContextKeys::loggerProvider());
        $eventLoggerProvider = $context->get(ContextKeys::eventLoggerProvider());

        assert(isset($tracerProvider, $meterProvider, $loggerProvider, $eventLoggerProvider, $propagator));

        return self::$globals = new self($tracerProvider, $meterProvider, $loggerProvider, $eventLoggerProvider, $propagator);
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$globals = null;
        self::$initializers = [];
    }
}
