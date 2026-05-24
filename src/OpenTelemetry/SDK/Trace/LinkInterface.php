<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace;

use Swoolefy\Library\OpenTelemetry\API\Trace\SpanContextInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

interface LinkInterface
{
    public function getSpanContext(): SpanContextInterface;
    public function getAttributes(): AttributesInterface;
}
