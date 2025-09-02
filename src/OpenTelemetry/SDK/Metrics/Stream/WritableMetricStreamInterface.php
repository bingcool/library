<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Stream;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

/**
 * @internal
 */
interface WritableMetricStreamInterface
{
    public function record(float|int $value, AttributesInterface $attributes, ContextInterface $context, int $timestamp): void;
}
