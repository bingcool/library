<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

/**
 * @internal
 */
interface AttributeProcessorInterface
{
    public function process(AttributesInterface $attributes, ContextInterface $context): AttributesInterface;
}
