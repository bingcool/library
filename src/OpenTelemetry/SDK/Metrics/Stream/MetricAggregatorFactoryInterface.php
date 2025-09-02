<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Stream;

/**
 * @internal
 */
interface MetricAggregatorFactoryInterface
{
    public function create(): MetricAggregatorInterface;
}
