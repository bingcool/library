<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\SDK\Metrics\Data\Temporality;

interface MetricSourceProviderInterface
{
    /**
     * @param string|Temporality $temporality
     */
    public function create($temporality): MetricSourceInterface;
}
