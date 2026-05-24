<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricRegistry;

use Swoolefy\Library\OpenTelemetry\API\Metrics\ObserverInterface;
use Swoolefy\Library\OpenTelemetry\Context\Context;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesFactoryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Stream\WritableMetricStreamInterface;

/**
 * @internal
 */
final class MultiObserver implements ObserverInterface
{
    /** @var list<WritableMetricStreamInterface>  */
    public array $writers = [];

    public function __construct(
        private readonly AttributesFactoryInterface $attributesFactory,
        private readonly int $timestamp,
    ) {
    }

    #[\Override]
    public function observe($amount, iterable $attributes = []): void
    {
        $context = Context::getRoot();
        $attributes = $this->attributesFactory->builder($attributes)->build();
        foreach ($this->writers as $writer) {
            $writer->record($amount, $attributes, $context, $this->timestamp);
        }
    }
}
