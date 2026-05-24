<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricRegistration;

use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricRegistrationInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricSourceProviderInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricSourceRegistryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\StalenessHandlerInterface;

/**
 * @internal
 */
final class RegistryRegistration implements MetricRegistrationInterface
{
    public function __construct(
        private readonly MetricSourceRegistryInterface $registry,
        private readonly StalenessHandlerInterface $stalenessHandler,
    ) {
    }

    #[\Override]
    public function register(MetricSourceProviderInterface $provider, MetricMetadataInterface $metadata): void
    {
        $this->registry->add($provider, $metadata, $this->stalenessHandler);
    }
}
