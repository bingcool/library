<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilterInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\MetricRegistry\MetricRegistryInterface;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * @internal
 */
interface MetricFactoryInterface
{
    /**
     * @param iterable<array{ViewProjection, MetricRegistrationInterface}> $views
     */
    public function createAsynchronousObserver(
        MetricRegistryInterface $registry,
        ResourceInfo $resource,
        InstrumentationScopeInterface $instrumentationScope,
        Instrument $instrument,
        int $timestamp,
        iterable $views,
    ): array;

    /**
     * @param iterable<array{ViewProjection, MetricRegistrationInterface}> $views
     */
    public function createSynchronousWriter(
        MetricRegistryInterface $registry,
        ResourceInfo $resource,
        InstrumentationScopeInterface $instrumentationScope,
        Instrument $instrument,
        int $timestamp,
        iterable $views,
        ?ExemplarFilterInterface $exemplarFilter = null,
    ): array;
}
