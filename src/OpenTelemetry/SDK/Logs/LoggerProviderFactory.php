<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs;

use Common\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use Common\Library\OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;
use Common\Library\OpenTelemetry\SDK\Sdk;

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
