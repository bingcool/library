<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

interface MetricSourceRegistryInterface
{
    public function add(MetricSourceProviderInterface $provider, MetricMetadataInterface $metadata, StalenessHandlerInterface $stalenessHandler): void;
}
