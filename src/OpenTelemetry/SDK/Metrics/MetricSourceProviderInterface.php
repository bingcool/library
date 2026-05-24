<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Data\Temporality;

interface MetricSourceProviderInterface
{
    /**
     * @param string|Temporality $temporality
     */
    public function create($temporality): MetricSourceInterface;
}
