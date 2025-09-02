<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace;

use Common\Library\OpenTelemetry\API\Trace\SpanContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

interface LinkInterface
{
    public function getSpanContext(): SpanContextInterface;
    public function getAttributes(): AttributesInterface;
}
