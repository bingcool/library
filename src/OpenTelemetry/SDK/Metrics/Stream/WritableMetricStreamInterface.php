<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\Stream;

use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

/**
 * @internal
 */
interface WritableMetricStreamInterface
{
    public function record(float|int $value, AttributesInterface $attributes, ContextInterface $context, int $timestamp): void;
}
