<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Metrics;

use Common\Library\OpenTelemetry\Context\ContextInterface;

interface UpDownCounterInterface extends SynchronousInstrument
{
    /**
     * @param float|int $amount amount to increment / decrement by
     * @param iterable<non-empty-string, string|bool|float|int|array|null> $attributes
     *        attributes of the data point
     * @param ContextInterface|false|null $context execution context
     */
    public function add($amount, iterable $attributes = [], $context = null): void;
}
