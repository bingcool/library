<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\SDK\Metrics;

interface PushMetricExporterInterface extends Metrics\MetricExporterInterface
{
    public function forceFlush(): bool;
}
