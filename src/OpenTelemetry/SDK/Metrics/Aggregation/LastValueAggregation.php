<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\Aggregation;

use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\AggregationInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Data;

/**
 * @implements AggregationInterface<LastValueSummary>
 */
final class LastValueAggregation implements AggregationInterface
{
    #[\Override]
    public function initialize(): LastValueSummary
    {
        return new LastValueSummary(null, 0);
    }

    /**
     * @param LastValueSummary $summary
     */
    #[\Override]
    public function record($summary, $value, AttributesInterface $attributes, ContextInterface $context, int $timestamp): void
    {
        if ($summary->value === null || $timestamp >= $summary->timestamp) {
            $summary->value = $value;
            $summary->timestamp = $timestamp;
        }
    }

    /**
     * @param LastValueSummary $left
     * @param LastValueSummary $right
     */
    #[\Override]
    public function merge($left, $right): LastValueSummary
    {
        return $right->timestamp >= $left->timestamp ? $right : $left;
    }

    /**
     * @param LastValueSummary $left
     * @param LastValueSummary $right
     */
    #[\Override]
    public function diff($left, $right): LastValueSummary
    {
        return $right->timestamp >= $left->timestamp ? $right : $left;
    }

    /**
     * @param array<LastValueSummary> $summaries
     */
    #[\Override]
    public function toData(
        array $attributes,
        array $summaries,
        array $exemplars,
        int $startTimestamp,
        int $timestamp,
        $temporality,
    ): Data\Gauge {
        $dataPoints = [];
        foreach ($attributes as $key => $dataPointAttributes) {
            if ($summaries[$key]->value === null) {
                continue;
            }

            $dataPoints[] = new Data\NumberDataPoint(
                $summaries[$key]->value,
                $dataPointAttributes,
                $startTimestamp,
                $timestamp,
                $exemplars[$key] ?? [],
            );
        }

        return new Data\Gauge(
            $dataPoints,
        );
    }
}
