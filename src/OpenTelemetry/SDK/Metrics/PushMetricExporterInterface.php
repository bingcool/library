<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\SDK\Metrics;

interface PushMetricExporterInterface extends Metrics\MetricExporterInterface
{
    public function forceFlush(): bool;
}
