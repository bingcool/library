<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporter;

use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanConverterInterface;

class NullSpanConverter implements SpanConverterInterface
{
    #[\Override]
    public function convert(iterable $spans): array
    {
        return [[]];
    }
}
