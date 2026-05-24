<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace;

use Swoolefy\Library\OpenTelemetry\API\Trace as API;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

final class Link implements LinkInterface
{
    public function __construct(
        private readonly API\SpanContextInterface $context,
        private readonly AttributesInterface $attributes,
    ) {
    }

    #[\Override]
    public function getSpanContext(): API\SpanContextInterface
    {
        return $this->context;
    }

    #[\Override]
    public function getAttributes(): AttributesInterface
    {
        return $this->attributes;
    }
}
