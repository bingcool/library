<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Common\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;

interface ViewRegistryInterface
{
    /**
     * @return iterable<ViewProjection>|null
     */
    public function find(Instrument $instrument, InstrumentationScopeInterface $instrumentationScope): ?iterable;
}
