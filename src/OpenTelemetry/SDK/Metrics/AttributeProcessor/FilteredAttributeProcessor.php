<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\AttributeProcessor;

use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\Attributes;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\AttributeProcessorInterface;

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
