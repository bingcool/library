<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\Data\DataInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\Data\Exemplar;
use Common\Library\OpenTelemetry\SDK\Metrics\Data\Temporality;

/**
 * @psalm-template T
 */
interface AggregationInterface
{
    /**
     * @psalm-return T
     */
    public function initialize();

    /**
     * @psalm-param T $summary
     * @psalm-param float|int $value
     */
    public function record($summary, $value, AttributesInterface $attributes, ContextInterface $context, int $timestamp): void;

    /**
     * @psalm-param T $left
     * @psalm-param T $right
     * @psalm-return T
     */
    public function merge($left, $right);

    /**
     * @psalm-param T $left
     * @psalm-param T $right
     * @psalm-return T
     */
    public function diff($left, $right);

    /**
     * @param array<AttributesInterface> $attributes
     * @param array<list<Exemplar>> $exemplars
     * @param string|Temporality $temporality
     * @psalm-param array<T> $summaries
     */
    public function toData(
        array $attributes,
        array $summaries,
        array $exemplars,
        int $startTimestamp,
        int $timestamp,
        $temporality,
    ): DataInterface;
}
