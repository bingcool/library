<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricRegistry;

use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Instrument;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Stream\MetricAggregatorFactoryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Stream\MetricAggregatorInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Stream\MetricStreamInterface;

/**
 * @internal
 */
interface MetricRegistryInterface extends MetricCollectorInterface
{
    public function registerSynchronousStream(Instrument $instrument, MetricStreamInterface $stream, MetricAggregatorInterface $aggregator): int;

    public function registerAsynchronousStream(Instrument $instrument, MetricStreamInterface $stream, MetricAggregatorFactoryInterface $aggregatorFactory): int;

    public function unregisterStreams(Instrument $instrument): array;
}
