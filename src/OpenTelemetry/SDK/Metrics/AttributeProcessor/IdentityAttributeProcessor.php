<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\AttributeProcessor;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\AttributeProcessorInterface;

/**
 * @internal
 */
final class IdentityAttributeProcessor implements AttributeProcessorInterface
{
    #[\Override]
    public function process(AttributesInterface $attributes, ContextInterface $context): AttributesInterface
    {
        return $attributes;
    }
}
