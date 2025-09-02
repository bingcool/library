<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\AttributeProcessor;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\Attributes;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\AttributeProcessorInterface;

/**
 * @internal
 */
final class FilteredAttributeProcessor implements AttributeProcessorInterface
{
    public function __construct(private readonly array $attributeKeys)
    {
    }

    #[\Override]
    public function process(AttributesInterface $attributes, ContextInterface $context): AttributesInterface
    {
        $filtered = [];
        foreach ($this->attributeKeys as $key) {
            $filtered[$key] = $attributes->get($key);
        }

        return new Attributes($filtered, 0);
    }
}
