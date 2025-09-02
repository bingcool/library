<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Exemplar;

use function count;
use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

final class HistogramBucketReservoir implements ExemplarReservoirInterface
{
    private readonly BucketStorage $storage;
    /**
     * @var list<float|int>
     */
    private array $boundaries;

    /**
     * @param list<float|int> $boundaries
     */
    public function __construct(array $boundaries)
    {
        $this->storage = new BucketStorage(count($boundaries) + 1);
        $this->boundaries = $boundaries;
    }

    #[\Override]
    public function offer($index, $value, AttributesInterface $attributes, ContextInterface $context, int $timestamp): void
    {
        $boundariesCount = count($this->boundaries);
        for ($i = 0; $i < $boundariesCount && $this->boundaries[$i] < $value; $i++) {
        }
        $this->storage->store($i, $index, $value, $attributes, $context, $timestamp);
    }

    #[\Override]
    public function collect(array $dataPointAttributes): array
    {
        return $this->storage->collect($dataPointAttributes);
    }
}
