<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\Exemplar;

use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Data\Exemplar;

interface ExemplarReservoirInterface
{
    public function offer(int|string $index, float|int $value, AttributesInterface $attributes, ContextInterface $context, int $timestamp): void;

    /**
     * @param array<AttributesInterface> $dataPointAttributes
     * @return array<Exemplar>
     */
    public function collect(array $dataPointAttributes): array;
}
