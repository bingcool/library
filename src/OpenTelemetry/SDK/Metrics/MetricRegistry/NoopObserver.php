<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricRegistry;

use Swoolefy\Library\OpenTelemetry\API\Metrics\ObserverInterface;

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
