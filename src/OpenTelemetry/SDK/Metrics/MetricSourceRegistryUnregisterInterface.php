<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\SDK\Metrics\MetricRegistry\MetricCollectorInterface;

/**
 * To be replaced by MetricProducer abstraction.
 *
 * @internal
 */
interface MetricSourceRegistryUnregisterInterface
{
    public function unregisterStream(MetricCollectorInterface $collector, int $streamId): void;
}
