<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Resource\ResourceInfo;
use Swoolefy\Library\OpenTelemetry\SDK\Sdk;

class LoggerProviderFactory
{
    public function create(?MeterProviderInterface $meterProvider = null, ?ResourceInfo $resource = null): LoggerProviderInterface
    {
        if (Sdk::isDisabled()) {
            return NoopLoggerProvider::getInstance();
        }
        $exporter = (new ExporterFactory())->create();
        $processor = (new LogRecordProcessorFactory())->create($exporter, $meterProvider);
        $instrumentationScopeFactory = new InstrumentationScopeFactory((new LogRecordLimitsBuilder())->build()->getAttributeFactory());

        return new LoggerProvider($processor, $instrumentationScopeFactory, $resource);
    }
}
