<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\View;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Instrument;

interface SelectionCriteriaInterface
{
    public function accepts(Instrument $instrument, InstrumentationScopeInterface $instrumentationScope): bool;
}
