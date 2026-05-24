<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace;

interface SpanConverterInterface
{
    public function convert(iterable $spans): array;
}
