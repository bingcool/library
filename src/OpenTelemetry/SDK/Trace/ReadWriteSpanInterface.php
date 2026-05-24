<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace;

use Swoolefy\Library\OpenTelemetry\API\Trace as API;

interface ReadWriteSpanInterface extends API\SpanInterface, ReadableSpanInterface
{
}
