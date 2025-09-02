<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs;

use Common\Library\OpenTelemetry\API\Logs\LoggerInterface;
use Common\Library\OpenTelemetry\API\Logs\NoopLogger;
use Common\Library\OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;

class NoopLoggerProvider implements LoggerProviderInterface
{
    public static function getInstance(): self
    {
        static $instance;

        return $instance ??= new self();
    }

    #[\Override]
    public function getLogger(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): LoggerInterface
    {
        return NoopLogger::getInstance();
    }

    #[\Override]
    public function shutdown(): bool
    {
        return true;
    }

    #[\Override]
    public function forceFlush(): bool
    {
        return true;
    }

    #[\Override]
    public function updateConfigurator(Configurator $configurator): void
    {
        //no-op
    }
}
