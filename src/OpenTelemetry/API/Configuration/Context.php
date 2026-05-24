<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Configuration;

use function class_alias;
use Swoolefy\Library\OpenTelemetry\API\Logs\LoggerProviderInterface;
use Swoolefy\Library\OpenTelemetry\API\Logs\NoopLoggerProvider;
use Swoolefy\Library\OpenTelemetry\API\Metrics\MeterProviderInterface;
use Swoolefy\Library\OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use Swoolefy\Library\OpenTelemetry\API\Trace\NoopTracerProvider;
use Swoolefy\Library\OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Context
{
    public function __construct(
        public readonly TracerProviderInterface $tracerProvider = new NoopTracerProvider(),
        public readonly MeterProviderInterface $meterProvider = new NoopMeterProvider(),
        public readonly LoggerProviderInterface $loggerProvider = new NoopLoggerProvider(),
        public readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }
}

/** @phpstan-ignore-next-line @phan-suppress-next-line PhanUndeclaredClassReference */
class_alias(Context::class, \OpenTelemetry\Config\SDK\Configuration\Context::class);
