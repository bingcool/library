<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace\SpanExporter;

use Common\Library\OpenTelemetry\SDK\Trace\SpanConverterInterface;

class NullSpanConverter implements SpanConverterInterface
{
    #[\Override]
    public function convert(iterable $spans): array
    {
        return [[]];
    }
}
