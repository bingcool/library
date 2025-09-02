<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\MetricRegistry;

use Common\Library\OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * @internal
 */
final class NoopObserver implements ObserverInterface
{
    #[\Override]
    public function observe($amount, iterable $attributes = []): void
    {
        // no-op
    }
}
