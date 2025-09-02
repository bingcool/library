<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Exemplar;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

final class NoopReservoir implements ExemplarReservoirInterface
{
    #[\Override]
    public function offer($index, $value, AttributesInterface $attributes, ContextInterface $context, int $timestamp): void
    {
        // no-op
    }

    #[\Override]
    public function collect(array $dataPointAttributes): array
    {
        return [];
    }
}
