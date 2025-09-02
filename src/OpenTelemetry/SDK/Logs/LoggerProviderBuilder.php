<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs;

use Common\Library\OpenTelemetry\SDK\Common\Attribute\Attributes;
use Common\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use Common\Library\OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
use Common\Library\OpenTelemetry\SDK\Logs\Processor\MultiLogRecordProcessor;
use Common\Library\OpenTelemetry\SDK\Logs\Processor\NoopLogRecordProcessor;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;

class LoggerProviderBuilder
{
    /** @var array<LogRecordProcessorInterface> */
    private array $processors = [];
    private ?ResourceInfo $resource = null;
    private ?Configurator $configurator = null;

    public function addLogRecordProcessor(LogRecordProcessorInterface $processor): self
    {
        $this->processors[] = $processor;

        return $this;
    }

    public function setResource(ResourceInfo $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function build(): LoggerProviderInterface
    {
        return new LoggerProvider(
            $this->buildProcessor(),
            new InstrumentationScopeFactory(Attributes::factory()),
            $this->resource,
            configurator: $this->configurator ?? Configurator::logger(),
        );
    }

    public function setConfigurator(Configurator $configurator): self
    {
        $this->configurator = $configurator;

        return $this;
    }

    private function buildProcessor(): LogRecordProcessorInterface
    {
        return match (count($this->processors)) {
            0 => NoopLogRecordProcessor::getInstance(),
            1 => $this->processors[0],
            default => new MultiLogRecordProcessor($this->processors),
        };
    }
}
