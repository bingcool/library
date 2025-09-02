<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

final class ViewProjection
{
    /**
     * @param list<string>|null $attributeKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $unit,
        public readonly ?string $description,
        public readonly ?array $attributeKeys,
        public readonly ?AggregationInterface $aggregation,
    ) {
    }
}
