<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\Stream;

use Swoolefy\Library\OpenTelemetry\SDK\Metrics\AggregationInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\AttributeProcessorInterface;

/**
 * @internal
 */
final class MetricAggregatorFactory implements MetricAggregatorFactoryInterface
{
    public function __construct(
        private readonly ?AttributeProcessorInterface $attributeProcessor,
        private readonly AggregationInterface $aggregation,
    ) {
    }

    #[\Override]
    public function create(): MetricAggregatorInterface
    {
        return new MetricAggregator($this->attributeProcessor, $this->aggregation);
    }
}
