<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

interface MetricReaderInterface
{
    public function collect(): bool;

    public function shutdown(): bool;

    public function forceFlush(): bool;
}
