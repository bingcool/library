<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\MetricRegistry;

/**
 * @internal
 */
interface MetricCollectorInterface
{
    public function collectAndPush(iterable $streamIds): void;
}
