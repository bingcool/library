<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

interface MetricSourceRegistryInterface
{
    public function add(MetricSourceProviderInterface $provider, MetricMetadataInterface $metadata, StalenessHandlerInterface $stalenessHandler): void;
}
