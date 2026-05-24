<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\AttributeProcessor;

use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\AttributeProcessorInterface;

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
