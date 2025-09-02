<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Exemplar;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

/**
 * The exemplar spec is not yet stable, and can change at any time.
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/sdk.md#exemplar
 */
final class FilteredReservoir implements ExemplarReservoirInterface
{
    public function __construct(
        private readonly ExemplarReservoirInterface $reservoir,
        private readonly ExemplarFilterInterface $filter,
    ) {
    }

    #[\Override]
    public function offer($index, $value, AttributesInterface $attributes, ContextInterface $context, int $timestamp): void
    {
        if ($this->filter->accepts($value, $attributes, $context, $timestamp)) {
            $this->reservoir->offer($index, $value, $attributes, $context, $timestamp);
        }
    }

    #[\Override]
    public function collect(array $dataPointAttributes): array
    {
        return $this->reservoir->collect($dataPointAttributes);
    }
}
