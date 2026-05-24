<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricRegistry\MetricCollectorInterface;

/**
 * To be replaced by MetricProducer abstraction.
 *
 * @internal
 */
interface MetricSourceRegistryUnregisterInterface
{
    public function unregisterStream(MetricCollectorInterface $collector, int $streamId): void;
}
