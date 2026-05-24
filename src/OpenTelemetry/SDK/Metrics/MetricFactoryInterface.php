<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilterInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricRegistry\MetricRegistryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Resource\ResourceInfo;

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
