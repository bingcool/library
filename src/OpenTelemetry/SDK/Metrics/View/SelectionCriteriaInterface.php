<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\View;

use Common\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\Instrument;

interface SelectionCriteriaInterface
{
    public function accepts(Instrument $instrument, InstrumentationScopeInterface $instrumentationScope): bool;
}
