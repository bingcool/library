<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace;

interface IdGeneratorInterface
{
    public function generateTraceId(): string;

    public function generateSpanId(): string;
}
