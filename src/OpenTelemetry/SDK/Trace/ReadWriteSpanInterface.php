<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace;

use Common\Library\OpenTelemetry\API\Trace as API;

interface ReadWriteSpanInterface extends API\SpanInterface, ReadableSpanInterface
{
}
