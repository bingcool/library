<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Instrumentation;

use Common\Library\OpenTelemetry\API\Metrics\MeterInterface;
use Common\Library\OpenTelemetry\API\Metrics\MeterProviderInterface;
use Common\Library\OpenTelemetry\API\Trace\TracerInterface;
use Common\Library\OpenTelemetry\API\Trace\TracerProviderInterface;
use Common\Library\OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Psr\Log\LoggerInterface;

interface InstrumentationInterface
{
    public function getName(): string;

    public function getVersion(): ?string;

    public function getSchemaUrl(): ?string;

    public function init(): bool;

    public function activate(): bool;

    public function setPropagator(TextMapPropagatorInterface $propagator): void;

    public function getPropagator(): TextMapPropagatorInterface;

    public function setTracerProvider(TracerProviderInterface $tracerProvider): void;

    public function getTracerProvider(): TracerProviderInterface;

    public function getTracer(): TracerInterface;

    public function setMeterProvider(MeterProviderInterface $meterProvider): void;

    public function getMeter(): MeterInterface;

    public function setLogger(LoggerInterface $logger): void;

    public function getLogger(): LoggerInterface;
}
