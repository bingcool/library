<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

/**
 * @internal
 */
interface AttributeProcessorInterface
{
    public function process(AttributesInterface $attributes, ContextInterface $context): AttributesInterface;
}
