<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\MetricRegistration;

use Common\Library\OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\MetricRegistrationInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\MetricSourceProviderInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\MetricSourceRegistryInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\StalenessHandlerInterface;

/**
 * @internal
 */
final class MultiRegistryRegistration implements MetricRegistrationInterface
{
    /**
     * @param iterable<MetricSourceRegistryInterface> $registries
     */
    public function __construct(
        private readonly iterable $registries,
        private readonly StalenessHandlerInterface $stalenessHandler,
    ) {
    }

    #[\Override]
    public function register(MetricSourceProviderInterface $provider, MetricMetadataInterface $metadata): void
    {
        foreach ($this->registries as $registry) {
            $registry->add($provider, $metadata, $this->stalenessHandler);
        }
    }
}
